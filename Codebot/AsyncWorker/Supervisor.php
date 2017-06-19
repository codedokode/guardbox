<?php

namespace Codebot\Worker;

use Codebot\LoggerTrait;
use Codebot\Stream2\MemorySink;
use Codebot\Task;
use Codebot\Util\DeferredWithThrow;
use Codebot\Util\ProcessHelper;
use Codebot\Util\PromiseUtil;
use Codebot\Util\PromiseWithThrow;
use Codebot\Worker\WorkerFailedException;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise as PromiseFunctions;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\FulfilledPromise;
use React\Promise\Promise;
use React\Promise\PromisorInterface;
use React\Promise\Timer;
use React\Promise\Timer\TimeoutException;
use React\Stream\ReadableStream;

/**
 * We use worker processes to run untrusted code in a sandbox.
 *
 * Each Supervisor object controls a slave worker process 
 * that has an unique slot number. At any moment there
 * should be only one Supervisor for every slot. 
 *
 * Supervisor runs external scripts to prepare sandbox, 
 * to start the worker process, to kill it if it used too much resources.
 *
 * One Supervisor object can run only one task. After finishing it
 * (successfully or not) a new Supervisor object must be created.
 *
 * There are several external scripts that are configured in 
 * WorkerConfig:
 *
 * - sandbox cleanup script
 * - sandbox prepare script
 * - start worker script
 * - kill worker script (used if worker doesn't terminate in time)
 *
 * The list below shows a sequence of scripts run and corresponding
 * states:
 *
 * - STATE_CREATED              - initial state, ready for start()
 *
 * After start() is called:
 *
 * - STATE_CLEANUP                - running cleanup script
 * - STATE_PREPARE             - running prepare VM script
 * - STATE_READY                  - ready to run a task
 *
 * After runTask() is called:
 *
 * - STATE_SAVING_CODE                - saving code to a temporary file
 * - STATE_STARTING_WORKER        - starting a child process to execute code
 * - STATE_RUNNING                - running a program
 * 
 * Now we are waiting either for worker termination, or timeout,
 * or for destroy() call, whatever happens first. When any of this event 
 * occurs we return the result from tunTask() immediately.
 *
 * The following states occur only if the worker didn't terminate
 * in time:
 *      
 * - STATE_KILLING                - running kill script
 * - STATE_WAIT_FOR_TERMINATION   - waiting for worker termination with timeout,
 *                                   if timeout is exceeded we move to a failed state
 *
 * After child process has terminated, we move to the following states:
 *  
 * - STATE_CLEANUP                - running cleanup script after worker termination
 * - STATE_DONE                   - everything is done, getFinishedPromise() is resolved 
 *
 * Whenever any of scripts fails or takes too much time or termination 
 * timeout is exceeded, we switch to a special failed state and 
 * getFinishedPromise() is rejected:
 *
 * - STATE_FAILED                 - an error occured and the worker became unusable 
 *
 * STATE_DONE and STATE_FAILED are the final states.
 * 
 * After calling start(), Supervisor does the following: 
 *
 * - runs old sandbox clean up script
 * - runs prepare sandbox script
 * 
 * start() method returns a promise that can be used to detect 
 * the moment when Supervisor is ready to runTask().
 *
 * Stderr and stdout data from scripts are sent to a logger.
 * 
 * After this, we can run a program with runTask(). 
 * The program code is saved to a file and executed. The 
 * method returns a promise that will resolve into 
 * WorkerResult.
 *
 * After running a program the Supervisor cleans up the sandbox.
 *
 * You can find that worker is finished (or failed) with the 
 * promise obtained from getFinishedPromise() method.
 *
 * If any of the scripts fails or times out, an error is reported through
 * getFinishedPromise(). The error can happen even after successful
 * program execution, for example if a cleanup script fails.
 */
class Supervisor
{    
    use LoggerTrait;

    const STATE_CREATED = 'created';

    /** Running clean up sandbox script */
    const STATE_CLEANUP = 'cleanup';

    /** Running prepare sandbox script */
    const STATE_PREPARE = 'prepare';

    /** Ready to run code, waiting for runTask() call */
    const STATE_READY = 'ready';

    /** Saving program code into sandbox */
    const STATE_SAVING_CODE = 'saving_code';

    /** Starting a child process that will execute code */
    const STATE_STARTING_WORKER = 'starting_worker';

    /** Code is running */
    const STATE_RUNNING = 'running';

    /** Running kill script because of timeout or abort request */
    const STATE_KILLING = 'killing';

    /** Waiting for child process to terminate */
    const STATE_WAIT_FOR_TERMINATION = 'wait_for_termination';

    /** Program successfully terminated or was killed */
    const STATE_DONE = 'done';

    /** Unexpected exception occured and worker became unusable */
    const STATE_FAILED = 'failed';

    /** @var LoopInterface */
    private $loop;

    /** Polling interval for $process->start() */
    private $procInterval = 0.05;

    /** @var Deferred   resolved when worker has finished 
        (either successfully or has failed) */
    private $finishDeferred;

    /** @var WorkerConfig */
    private $config;

    /** @var Deferred   resolves into a WorkerTask */
    private $taskDeferred;

    /** @var Deferred   resolves into a WorkerTaskResult */
    private $resultDeferred;

    /** @var Deferred   resolves into a kill reason if there is 
                        a request to kill the worker */
    private $killDeferred;

    private $state = self::STATE_CREATED;

    private $slotNumber;

    private $childProcess;

    private $workerProcess;

    public function __construct(LoopInterface $loop, WorkerConfig $config, $slotNumber)
    {
        assert(!!$slotNumber);
        $this->loop = $loop;
        $this->finishDeferred = new DeferredWithThrow;
        $this->slotNumber = $slotNumber;
        $this->config = $config;

        // Resolves into a WorkerTask when runTask() is called
        $this->taskDeferred = new Deferred;
        $this->resultDeferred = new Deferred;
        $this->killDeferred = new Deferred;
    }

    public function getSlot()
    {
        return $this->slotNumber;
    }

    public function getName()
    {
        return "worker/{$this->slotNumber}";
    }
    
    /**
     * Returns a promise that: 
     *
     * - is notified on every worker state change
     * - is resolved when the worker is successfully finished 
     * - is rejected with WorkerFailedException if worker fails to initialize or 
     *     to run a program or clean up a sandbox
     *
     * After this promise is resolved or rejected the worker can be 
     * destroyed and its slot reused.
     *
     * This promise will not throw UnhandledRejectionException even
     * if rejected.
     */
    public function getFinishedPromise()
    {
        return $this->finishDeferred->promise();
    }

    /**
     * Returns true either if all scripts have successfully finished
     * or if worker failed to run any of the scripts.
     */
    public function isFinished()
    {
        return $this->state == self::STATE_DONE || 
            $this->state == self::STATE_FAILED;
    }
    
    public function getState()
    {
        return $this->state;
    }

    /**
     * Returns a promise, resolved when the worker is ready to 
     * execute the task or rejected if it failed to initialize.
     */
    public function start($canSkipCleanup = false)
    {
        if ($this->state !== self::STATE_CREATED) {
            throw new \LogicException("Cannot call start() twice");
        }

        // cleanup and prepare sandbox
        $readyPromise = $this->initAsync($canSkipCleanup);
// PromiseUtil::debugPromise($readyPromise, '$readyPromise (in start)');

        // run worker
        $workerDonePromise = PromiseUtil::allSuccess([$readyPromise, $this->taskDeferred->promise()])->
            then(function ($args) {
                list($dummy, $task) = $args;
                list($resultPromise, $terminatedPromise) = $this->runTaskAsync(
                    $task,
                    $this->config->maxExecutionTime,
                    $this->config->waitForTerminationTimeout
                );

                $this->resultDeferred->resolve($resultPromise);
                return $terminatedPromise;
        });
// PromiseUtil::debugPromise($workerDonePromise, '$workerDonePromise');
        // clean up after worker process has terminated
        $cleanupDonePromise = $workerDonePromise->then(function () {
            return $this->startCleanupAsync();
        });

// PromiseUtil::debugPromise($this->getFinishedPromise(), '$finishedPromise');

        // After either is OK or we failed somewhere
        $cleanupDonePromise->then(function () {
            $this->changeState(self::STATE_DONE);
        })->otherwise(function ($e) {
            $this->resultDeferred->reject($e);
            $this->setFailedState($e);
        });

// PromiseUtil::debugPromise($cleanupDonePromise, '$cleanupDonePromise');

        // $readyPromiseWrapper = PromiseWithThrow::wrap($readyPromise);
        return $readyPromise;
    }

    public function isReady()
    {
        return $this->state === self::STATE_READY;
    }

    public function runTask(WorkerTask $task)
    {
        if (!$this->isReady()) {
            throw new \LogicException("This worker ({$this->getName()}) is not ready");
        }

        $this->taskDeferred->resolve($task);
        return $this->resultDeferred->promise();
    }

    /**
     * Kills a worker process if it was running. Cleanup is 
     * not called.
     */
    public function destroy()
    {
        if ($this->config->canSignal) {
            if ($this->childProcess && $this->childProcess->isRunning()) {
                $this->childProcess->terminate(9);
            }

            if ($this->workerProcess && $this->workerProcess->isRunning()) {
                $this->workerProcess->terminate(9);
            }
        }

        if ($this->isWorkerRunning()) {
            $this->killWorkerProcess(WorkerTaskResult::KILLED_BY_DESTROY_REQUEST);
        }
    }


    /**
     * Initializes a sandbox. Returns a promise that resolves when 
     * everything is ready, or rejects on error.
     */
    private function initAsync($canSkipCleanup)
    {
        $initPromise = new FulfilledPromise(true);
        if (!$canSkipCleanup) {
            // Run a cleanup script first
            $initPromise = $initPromise->then(function () { 
                return $this->startCleanupAsync(); 
            });
        }

        // Run a prepare script
        $readyPromise = $initPromise->then(function () {
            return $this->startPrepareAsync();
        });

        $readyPromise->then(function () {
            $this->changeState(self::STATE_READY);
        });

        return $readyPromise;
    }

    /**
     * Runs the task in the sandox. Returns 2 promises: [taskResult, terminateResult]
     *
     * - taskResult is resolved when a worker has terminated or was requested to be killed
     *     and is rejected when: 
     *         - failed to save a task
     *         - failed to start a worker
     *
     * - terminateResult is resolved when worker has terminated
     *     and is rejected if it failed to start or to terminate in time
     */
    private function runTaskAsync(
        WorkerTask $task,
        $timeout,
        $waitForTerminationTimeout)
    {        
        $startTime = null;
        $killPromise = $this->killDeferred->promise();

        // Save the code to a file
        $savedPromise = $this->saveProgramAsync($task);

        $exitedPromise = $savedPromise->then(function () use (&$startTime, $task, $timeout) {
            $startTime = microtime(true);
            $resultPromise = $this->runWorkerProcessAsync($task);

            $timeoutPromise = Timer\timeout($resultPromise, $timeout, $this->loop);
            $timeoutPromise->otherwise(function (TimeoutException $e) {
                if ($this->isWorkerRunning()) {
                    $this->killWorkerProcess(WorkerTaskResult::KILLED_TIMEOUT);
                }
            });

            return $resultPromise;
        });

        // If kill was requested
        $killTaskResult = $killPromise->then(function ($reason) use ($task, &$startTime) {

            $killedResult = WorkerTaskResult::createForKilled($task, $reason);
            $killedResult->executionTime = microtime(true) - $startTime;

            return $killedResult;
        });

        
        $killDonePromise = $killPromise->then(function ($reason) {
                if (!$this->isWorkerRunning()) {
                    return;
                }

                return $this->startKillScriptAsync();
        });

        // Wait for termination
        $killDonePromise = $killDonePromise->then(function () 
            use ($waitForTerminationTimeout, $exitedPromise) {

                $this->setState(self::STATE_WAIT_FOR_TERMINATION);

                $timeout = Timer\reject($waitForTerminationTimeout, $this->loop);
                $exitedPromise->done(function () {
                    $timeout->cancel();
                });

                return $timeout;
        });

        $terminatedPromise = PromiseUtil::first([$exitedPromise, $killDonePromise]);
        $taskResultPromise = PromiseUtil::first([$exitedPromise, $killTaskResult]);

        return [
            $taskResultPromise,
            $terminatedPromise
        ];
    }

    private function killWorkerProcess($reason)
    {
        assert($this->isWorkerRunning());
        $this->killDeferred->resolve($reason);
    }

    private function isWorkerRunning()
    {
        return $this->state == self::STATE_RUNNING;
    }

    private function changeState($newState)
    {
        assert(!$this->isFinished());

        $this->state = $newState;
        $this->finishDeferred->notify($newState);
        $this->getLogger()->info('{name} state={state}', [
            'name'  =>  $this->getName(),
            'state' =>  $newState
        ]);

        if ($newState == self::STATE_DONE) {
            $this->finishDeferred->resolve(true);
        }
    }

    /**
     * Changes worker state to 'failed' and reports an error 
     * via progress deferred.
     */
    private function setFailedState(/* WorkerFailedException */ $error)
    {
        $this->changeState(self::STATE_FAILED);
        $this->finishDeferred->reject($error);
        //throw new \Exception("Unexpected error in worker {$this->slotNumber}", 0, $error);
    }

    private function startCleanupAsync()
    {
        $this->changeState(self::STATE_CLEANUP);

        return $this->runHelperScriptAsync(
            $this->config->cleanupCommand,
            'cleanupCommand', 
            $this->config->scriptExecuteTimeout
        );
    }

    private function startPrepareAsync()
    {
        $this->changeState(self::STATE_PREPARE);

        return $this->runHelperScriptAsync(
            $this->config->prepareCommand,
            'prepareCommand', 
            $this->config->scriptExecuteTimeout
        );
    }

    private function saveProgramAsync(WorkerTask $task)
    {
        $path = $this->config->getProgramPath($this->getSlot());

        $this->changeState(self::STATE_SAVING_CODE);
        $this->getLogger()->debug("Save program to {path}", [
            'path' => $path
        ]);

        $writePromise = PromiseUtil::writeFileAsync(
            $path,
            $task->program,
            $this->loop
        );

        $writeWithTimeout = Timer\timeout(
            $writePromise,
            $this->config->scriptExecuteTimeout,
            $this->loop
        );

        return $writeWithTimeout;
    }

    private function startKillScriptAsync()
    {
        $this->changeState(self::STATE_KILLING);

        if ($this->config->canSignal) {
            if ($this->workerProcess && $this->workerProcess->isRunning()) {
                $this->workerProcess->terminate(9);
            }

            return new FulfilledPromise(true);
        }

        return $this->runHelperScriptAsync(
            $this->config->killWorkerCommand,
            'killWorkerCommand', 
            $this->config->scriptExecuteTimeout
        );
    }

    private function buildCommand(array $args)
    {
        $replace = [
            'programPath'       => $this->config->getProgramPath($this->getSlot()),
            'innerProgramPath'  => $this->config->innerProgramPath
        ];

        $args = ProcessHelper::substituteArguments($args, $replace);
        $command = ProcessHelper::buildCommandLine($args);
        return $command;
    }

    /**
     * @return ExtendedPromiseInterface
     */
    private function runHelperScriptAsync(array $args, $scriptName, $timeout)
    {
        // if ($this->childProcess) {
        //     throw new \Exception("Cannot start helper script $scriptName while another one is running");
        // }

        $resultDeferred = null;
        $resultDeferred = new Deferred(function ($resolve, $reject, $notify) 
            use (&$resultDeferred, $scriptName) {

            $this->getLogger()->info("{name}: helper script {scriptName} cancelled", [
                'name'  =>   $this->getName(),
                'scriptName' => $scriptName
            ]);

            // On cancel kill process 
            if ($this->childProcess && $this->childProcess->isRunning() 
                    && $this->config->canSignal) {
                $this->childProcess->terminate(9);
            }

            $reject(new WorkerFailedException("Helper script $scriptName was cancelled"));
        });

        $command = $this->buildCommand($args);

        $this->startHelperProcess($command, $scriptName, $resultDeferred);
        // $this->childProcess->on('exit', function () {
        //     $this->childProcess = null;
        // });

        // Add timeout
        $resultPromise = Timer\timeout($resultDeferred->promise(), $timeout, $this->loop);

        return $resultPromise;
    }

    private function startHelperProcess($command, $scriptName, Deferred $resultDeferred)
    {
        if ($this->childProcess) {
            throw new \Exception("Cannot start helper script $scriptName while another one is running");
        }

        $this->getLogger()->debug("{name}: Starting script {scriptName}: {command}", [
            'name'      =>  $this->getName(),
            'scriptName'=>  $scriptName,
            'command'   =>  $command
        ]);

        $process = new Process($command, null, null, [
            'bypass_shell' => true // works only on Windows
        ]);

        $this->childProcess = $process;

        $startTime = null;

        $process->on('exit', function ($code, $signal) 
            use ($resultDeferred, $scriptName, &$startTime) {

            $this->childProcess = null;

            $time = microtime(true) - $startTime;
            $this->getLogger()->debug(sprintf(
                "%s: Script %s finished in %.3f ms with code %d, signal %d",
                $this->getName(),
                $scriptName,
                $time * 1000,
                $code,
                $signal
            ));

            if ($code || $signal) {
                // $this->getLogger()->error(
                //     "Worker failed to run script {$scriptName}: code={$code}, signal={$signal}");

                $resultDeferred->reject(new WorkerFailedException(
                    "Script {$scriptName} failed with signal {$signal}, code {$code}"));
                return;
            }

            $resultDeferred->resolve(true);
        });

        $startTime = microtime(true);
        $process->start($this->loop, $this->procInterval);

        $process->stdin->close();

        ProcessHelper::pipeStreamToLogger(
            $process->stdout, 
            $this->getLogger(), 
            "{$this->getName()}/stdout: "
        );
        ProcessHelper::pipeStreamToLogger(
            $process->stderr, 
            $this->getLogger(), 
            "{$this->getName()}/stderr: "
        );        

        return $process;
    }

    /**
     * @return ExtendedPromiseInterface 
     */
    private function runWorkerProcessAsync(WorkerTask $task)
    {
        $args = $this->config->runWorkerCommand;
        $command = $this->buildCommand($args);

        $resultDeferred = new Deferred;

        $this->startWorkerProcess($command, $task, $resultDeferred);        

        return $resultDeferred->promise();
    }

    private function startWorkerProcess($command, WorkerTask $task, Deferred $taskResultDeferred)
    {
        if ($this->workerProcess) {
            throw new \Exception("Trying to run a worker child process while another one is running");
        }

        $this->changeState(self::STATE_RUNNING);        

        $this->getLogger()->debug("{name}: Starting worker process: {command}", [
            'name'      =>  $this->getName(),
            'scriptName'=>  $scriptName,
            'command'   =>  $command
        ]);

        $process = new Process($command, null, null, [
            'bypass_shell' => true // works only on Windows
        ]);

        $this->workerProcess = $process;

        $startTime = null;
        $process->on('exit', function ($code, $signal) use ($task, $taskResultDeferred, &$startTime) {

            $this->workerProcess = null;

            $time = microtime(true) - $startTime;
            $this->getLogger()->debug(sprintf(
                "%s: Worker finished in %.3f ms with code %d, signal %d",
                $this->getName(),
                $time * 1000,
                $code,
                $signal
            ));

            $taskResult = WorkerTaskResult::createSuccessful($task, $code, $signal);
            $taskResult->executionTime = $time;
            $taskResultDeferred->resolve($taskResult);
        });

        $startTime = microtime(true);
        $process->start($this->loop, $this->procInterval);

        if ($task->stdin) {
            $process->stdin->resume();
            $process->stdin->end($task->stdin);
        } else {
            $process->stdin->end();
        }

        if ($task->stdout) {
            $this->pipeStreamToSink($process->stdout, $task->stdout, 'stdout');
        } else {
            $process->stdout->close();
        }

        if ($task->stderr) {
            $this->pipeStreamToSink($process->stderr, $task->stderr, 'stderr');
        } else {
            $process->stderr->close();
        }        
        
        return $process;
    }

    private function pipeStreamToSink(ReadableStream $readFrom, MemorySink $writeTo, $streamName)
    {
        $readFrom->pipe($writeTo);

        // Error in memory sink means we have exceeded write limit
        // and must kill the child process
        $writeTo->on('error', function ($e) {
            if ($this->isWorkerRunning()) {
                $this->killWorkerProcess(WorkerTaskResult::KILLED_TOO_MUCH_OUTPUT);
            }
        });

        // Error on the read side probably means the pipe ws closed so 
        // we will only log it 
        $readFrom->on('error', function ($e) use ($streamName) {
            $this->getLogger()->error('{name}: read error on {stream} from child process: {error}', [
                'name'  =>  $this->getName(),
                'stream'=>  $streamName,
                'error' =>  $e->getMessage()
            ]);
        });
    }
}