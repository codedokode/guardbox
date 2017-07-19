<?php

namespace Tests;

use Codebot\AsyncWorker\Supervisor;
use Codebot\AsyncWorker\WorkerConfig;
use Codebot\AsyncWorker\WorkerTask;
use Codebot\Stream2\MemorySink;
use Codebot\Util\ProcessHelper;
use Codebot\Util\PromiseUtil;
use React\EventLoop\StreamSelectLoop;
use React\Promise as PromiseFunction;
use React\Promise\PromiseInterface;
use Tests\Helper\CapturingLogger;
use Tests\Helper\FileHelper;
use Tests\Helper\TestContainer;
use Tests\Helper\TestProcessHelper;
use Tests\Helper\TestPromiseUtil;

class SupervisorTest extends \PHPUnit\Framework\TestCase
{
    private $rootDir;
    private $baseDir;
    private $loop;
    private $logger;

    private $trueCmd = ['/bin/true'];
    private $falseCmd = ['/bin/false'];

    public static function setUpBeforeClass()
    {
        FileHelper::cleanupOldTmpFiles();
    }    

    public function setUp()
    {
        $this->rootDir = FileHelper::createTmpDir();
        $baseDir = WorkerConfig::getVmBaseDirectory($this->rootDir, 1);
        $fs = TestContainer::getTestFilesystem();
        $fs->mkdir($baseDir);

        $this->baseDir = $baseDir;

        $this->loop = new StreamSelectLoop;
        $this->logger = TestContainer::getLogger();
    }

    public function tearDown()
    {
        TestProcessHelper::killDescendantProcesses();
        TestContainer::getTestFilesystem()->remove($this->rootDir);
    }

    /**
     * Test how iniialization errors are handled
     */
    public function testInitializationErrorHandling()
    {
        $true = $this->trueCmd;
        $false = $this->falseCmd;
        $slow = ['sleep', '10'];
        $signalled = ProcessHelper::createShellCommand('kill -15 $$');

        $this->checkInitCycle(true, $true, $true);

        $this->checkInitCycle(false, $false, $true);
        $this->checkInitCycle(false, $slow, $true);
        $this->checkInitCycle(false, $signalled, $true);

        $this->checkInitCycle(false, $true, $false);
        $this->checkInitCycle(false, $true, $slow);
        $this->checkInitCycle(false, $true, $signalled);
    }

    /**
     * Check how errors during initialization are processed
     */
    private function checkInitCycle($expect, $cleanupCmd, $prepareCmd)
    {
        $config = $this->createSupervisorConfig();
        $config->canSignal = true;
        $config->scriptExecuteTimeout = 3;
        $config->cleanupCommand = $cleanupCmd;
        $config->prepareCommand = $prepareCmd;

        $supervisor = $this->createSupervisor($config);
        $finishPromise = $supervisor->getFinishedPromise();

        // Handle rejections so no error is thrown
        TestPromiseUtil::handleRejection($finishPromise);

        $initPromise = $supervisor->start();

        $this->waitForAllPromises(10, [$initPromise]);

        if ($expect) {
            $this->assertTrue(PromiseUtil::isResolved($initPromise));
            $this->assertFalse(PromiseUtil::isCompleted($finishPromise));
        } else {
            $this->assertTrue(PromiseUtil::isRejected($initPromise));
            $this->assertTrue(PromiseUtil::isRejected($finishPromise));
        }

        $supervisor->destroy();
    }

    public function testCannotCallStartTwice()
    {
        $config = $this->createSupervisorConfig();
        $supervisor = $this->initSupervisor($config);
        $this->setExpectedException('Exception');
        $supervisor->start();
    }

    public function testCannotRunTaskWithoutInit()
    {
        $config = $this->createSupervisorConfig();
        $supervisor = $this->createSupervisor($config);
        $task = $this->createDummyTask();

        $this->setExpectedException('Exception');
        $supervisor->runTask($task);
    }

    public function testCannotRunTaskTwice()
    {
        $supervisor = $this->initDummySupervisor();
        $task1 = $this->createDummyTask();
        $task2 = $this->createDummyTask();

        $supervisor->runTask($task1);
        $this->waitForAllPromises(10, [$supervisor->getFinishedPromise()]);

        $this->setExpectedException('Exception');
        $supervisor->runTask($task2);
    }

    public function testScriptOutputIsLogged()
    {
        $config = $this->createSupervisorConfig();

        $config->cleanupCommand = ProcessHelper::createShellCommand(
            'echo "cleanup-stdout"; echo "cleanup-stderr" >&2'
        );

        $config->prepareCommand = ProcessHelper::createShellCommand(
            'echo "prepare-stdout"; echo "prepare-stderr" >&2'
        );

        $supervisor = $this->createSupervisor($config);
        $logger = new CapturingLogger;
        $logger->setNextLogger($this->logger);
        $supervisor->setLogger($logger);
        $initPromise = $supervisor->start();

        $this->waitForAllPromises(10, [$initPromise]);

        $messages = $logger->getMessages();

        $this->assertTrue($this->doesArrayContainString($messages, 'cleanup-stdout'));
        $this->assertTrue($this->doesArrayContainString($messages, 'cleanup-stderr'));
        $this->assertTrue($this->doesArrayContainString($messages, 'prepare-stdout'));
        $this->assertTrue($this->doesArrayContainString($messages, 'prepare-stderr'));

        $supervisor->destroy();
    }

    private function doesArrayContainString(array $messages, $string)
    {
        foreach ($messages as $message) {
            if (strpos($message, $string) !== false) {
                return true;
            }
        }

        return false;
    }
    
    public function testWorkerExitStatusReporting()
    {
        $exitCmd = ProcessHelper::createShellCommand('exit 5');
        $signalCmd = ProcessHelper::createShellCommand('kill -15 $$');
        $sleepCmd = ['sleep', 3];

        $this->checkExitStatus($exitCmd, false, 5, 0, 0, 3);
        $this->checkExitStatus($signalCmd, true, 0, 15, 0, 3);
        $this->checkExitStatus($sleepCmd, true, 0, 15, 2, 6);
    }

    private function checkExitStatus(
        $command, 
        $expectSignalled,
        $expectedCode, 
        $expectedSignal, 
        $minTime, 
        $maxTime)
    {
        $supervisor = $this->initSupervisorWithWorker($command);
        $task = $this->createDummyTask();
        $resultPromise = $supervisor->runTask($task);
        $finishPromise = $supervisor->getFinishedPromise();

        $this->waitForAllPromises(10, [$resultPromise]);

        $this->assertTrue(PromiseUtil::isCompleted($resultPromise));
        $result = PromiseUtil::peekValue($resultPromise);
        $this->assertNotNull($result);
        $this->assertInstanceOf(Codebot\AsyncWorker\WorkerTaskResult::class, $result);

        if ($expectSignalled) {
            $this->assertTrue($result->wasKilledBySignal());
            $this->assertEquals($expectedSignal, $result->exitSignal);
        } else {
            $this->assertTrue($result->hasExited());
            $this->assertEquals($expectedCode, $result->exitCode);
        }

        $this->assertGreaterThanOrEqual($minTime, $result->executionTime);
        $this->assertLessThanOrEqual($maxTime, $result->executionTime);

        $supervisor->destroy();
    }
    
    public function testWorkerInputOutput()
    {
        $printCmd = ProcessHelper::createShellCommand(
            'echo -n stdout-test; echo -n stderr-test >&2 '
        );

        $stdinCommand = ['cat'];
        $catProgramCommand = ['cat', '{programPath}'];       
        $tooMuchCmd = ['cat', '/dev/zero'];

        $this->checkWorkerIOCase($printCmd, '', '', 'stdout-test', 'stderr-test');
        $this->checkWorkerIOCase($stdinCommand, 'stdin-test', '', 'stdin-test', '');
        $this->checkWorkerIOCase($catProgramCommand, '', 'program-test', 'program-test', '');
        $this->checkWorkerIOCase($tooMuchCmd, '', '', '', '');
    }

    private function checkWorkerIOCase(array $cmd, $stdin, $program, $expectStdout, $expectStderr)
    {
        $supervisor = $this->initSupervisorWithWorker($cmd);
        $task = $this->createDummyTask();
        $task->program = $program;
        $task->stdin = $stdin;

        $resultPromise = $supervisor->runTask($task);

        $this->waitForAllPromises(10, [$resultPromise]);

        // $this->assertTrue(TestPromiseUtil::isResolved($resultPromise));
        $result = PromiseUtil::peekValue($resultPromise);

        // if ($isTooMuch) {
        //     $this->assertTrue($result->wasKilledOnTooMuchOutput());
        // } else {
            $stdout = $task->stdoutSink->getContents();
            $stderr = $task->stderrSink->getContents();
            $this->assertEquals($expectStdout, $stdout);
            $this->assertEquals($expectStderr, $stderr);
        // }
        $supervisor->destroy();
    }

    public function testWorkerTimeouts()
    {
        $fastWorker = ['true'];
        $slowWorker = ['sleep', '5'];
        $verySlowWorker = ['sleep', '15'];
        
        $dummyKill = ['true'];
        $failingKill = ['false'];
        $slowKill = ['sleep', '10'];

        $pidFile = $this->preparePidFilePath();

        $workerToKill = ProcessHelper::createShellCommand(
            "echo $$ > $pidFile; exec sleep 20"
        );
        $tooMuchWorker = ProcessHelper::createShellCommand(
            "echo $$ > $pidFile; exec cat /dev/zero"
        );

        $realKill = ProcessHelper::createShellCommand(
            "kill -9 $(< \"$pidFile\")"
        );

        // What if worker runs in time
        $this->checkSlowWorker($fastWorker, $dummyKill, 2, 7, false, true, false);

        // What if worker times out
        $this->checkSlowWorker($slowWorker, $dummyKill, 2, 7, true, true, false);

        // Test kill script is really called
        $this->checkSlowWorker($workerToKill, $realKill, 2, 3, true, true, false);

        // What if there is too much output
        $this->checkSlowWorker($tooMuchWorker, $realKill, 7, 5, false, true, true);
        
        // What if kill script fails
        $this->checkSlowWorker($slowWorker, $failingKill, 2, 7, true, false, false);
        
        // What if kill script times out
        $this->checkSlowWorker($slowWorker, $slowKill, 2, 7, true, false, false);

        // What if worker doesn't terminate anyway
        $this->checkSlowWorker($verySlowWorker, $dummyKill, 2, 3, true, false, false);
    }

    private function checkSlowWorker(
        array $workerCmd, 
        array $killCmd, 
        $waitWorker, 
        $waitTermination,  
        $isTimeouted,
        $isFinishSuccess,
        $isTooMuchIo)   
    {
        $config = $this->createSupervisorConfig();
        $config->runWorkerCommand = $workerCmd;
        $config->killWorkerCommand = $killCmd;
        $config->canSignal = false;
        $config->maxExecutionTime = $waitWorker;
        $config->waitForTerminationTimeout = $waitTermination;
        $config->executeScriptTimeout = 3;

        $supervisor = $this->initSupervisor($config);

        $task = $this->createDummyTask();

        // Set low limits
        $task->stdout = new MemorySink(100);
        $task->stderr = new MemorySink(100);

        $resultPromise = $supervisor->runTask($task);
        $finishPromise = $supervisor->getFinishedPromise();

        // Result must be available instantly
        $this->waitForAllPromises($waitWorker + 2, [$resultPromise]);

        $this->assertTrue(PromiseUtil::isResolved($resultPromise));
        $result = PromiseUtil::peekValue($resultPromise);

        if ($isTooMuchIo) {
            $this->assertTrue($result->wasKilledOnTooMuchOutput());
        } elseif ($isTimeouted) {
            $this->assertTrue($result->wasKilledOnTimeout());

            // Execution time must be approx. waitTime        
            $minTime = $waitTime - 1;
            $maxTime = $waitTime + 2;
            $this->assertGreaterThanOrEqual($minTime, $result->executionTime);
            $this->assertLessThanOrEqual($maxTime, $result->executionTime);
        } else {
            $this->assertTrue($result->hasExited());
        }

        $this->waitForAllPromises(20, [$finishPromise]);

        if ($isFinishSuccess) {
            $this->assertTrue(PromiseUtil::isResolved($finishPromise));
        } else {
            $this->assertTrue(PromiseUtil::isRejected($finishPromise));
        }

        $supervisor->destroy();
    }

    public function testProgressReport()
    {
        $config = $this->createSupervisorConfig();
        $supervisor = $this->createSupervisor($config);

        $statuses = [];
        $this->captureStatuses($supervisor, $statuses);

        $initPromise = $supervisor->start();

        $this->waitForAllPromises(10, [$initPromise]);
        $this->assertContains(Supervisor::STATE_PREPARE, $statuses);
        $this->assertEquals(Supervisor::STATE_READY, $supervisor->getState());

        $task = $this->createDummyTask();
        $resultPromise = $supervisor->runTask($task);

        $this->waitForAllPromises(10, [$resultPromise]);
        $this->assertContains(Supervisor::STATE_RUNNING, $statuses);

        $this->waitForAllPromises(10, [$supervisor->getFinishedPromise()]);
        $this->assertEquals(Supervisor::STATE_DONE, $supervisor->getState());

        $supervisor->destroy();

        // test for failure case
        $config = $this->createSupervisorConfig();
        $config->prepareCommand = ['false'];
        $super2 = $this->createSupervisor($config);
        $statuses2 = [];
        $this->captureStatuses($super2, $statuses2);

        $super2->start();

        $this->waitForAllPromises(10, [$super2->getFinishedPromise()]);
        $this->assertEquals(Supervisor::STATE_FAILED, $super2->getState());

        $super2->destroy();
    }

    private function createSupervisorConfig()
    {
        $config = new WorkerConfig;
        $config->cleanupCommand = $this->trueCmd;
        $config->prepareCommand = $this->trueCmd;
        $config->runWorkerCommand = $this->trueCmd;
        $config->killWorkerCommand = $this->trueCmd;
        $config->canSignal = true;
        $config->workerSandboxRoot = $this->rootDir;

        return $config;
    }

    private function createSupervisor(WorkerConfig $config)
    {
        $supervisor = new Supervisor($this->loop, $config, 1);
        $supervisor->setLogger($this->logger);
        $supervisor->getFinishedPromise()->otherwise(function ($e) {
            printf("Worker failed: %s\n", $e->getMessage());
        });

        return $supervisor;
    }

    private function initDummySupervisor()
    {
        $config = $this->createSupervisorConfig();
        return $this->initSupervisor($config);
    }

    private function initSupervisorWithWorker(array $runWorkerCommand)
    {
        $config = $this->createSupervisorConfig();
        $config->runWorkerCommand = $runWorkerCommand;
        return $this->initSupervisor($config);
    }
    
    private function initSupervisor(WorkerConfig $config)
    {
        $supervisor = $this->createSupervisor($config);
        $readyPromise = $supervisor->start();

        $this->waitForAllPromises(10, [$readyPromise]);
        $this->assertTrue(PromiseUtil::isResolved($readyPromise));

        return $supervisor;
    }

    private function createDummyTask()
    {
        $task = new WorkerTask;
        $task->uniqueId = mt_rand(1, 9999);
        $task->stdoutSink = new MemorySink(10000);
        $task->stderrSink = new MemorySink(10000);

        return $task;
    }

    private function preparePidFilePath()
    {
        $name = 'codebot-test-' . mt_rand(1,999999). '.pid';
        $path = $this->baseDir . '/' . $name;

        if (file_exists($path)) {
            unlink($path);
        }

        return $path;
    }

    private function waitForAllPromises($timeout, array $promises)
    {
        $isTimeout = TestPromiseUtil::waitForPromise(
            $this->loop, 
            $timeout, 
            PromiseUtil::allCompleted($promises)
        );

        if ($isTimeout) {
            $this->logger->warning('Timeout of {timeout} sec. exceeded while waiting on promise', [
                'timeout'   =>  $timeout
            ]);
        }
    }

    private function captureStatuses(Supervisor $supervisor, array &$statuses)
    {
        $supervisor->getFinishedPromise()->progress(function ($status) use (&$statuses) {
            $statuses[] = $status;
        });
    }
    
}
