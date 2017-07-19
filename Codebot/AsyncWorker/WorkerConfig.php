<?php

namespace Codebot\AsyncWorker;

class WorkerConfig
{
    /**
     * Array of arguments, e.g. ['/bin/kill', '{pid}']
     *
     * {programPath} will be replaced with a path to a program
     * {pid} in killWorkerCommand will be replaced with worker's pid
     */
    public $cleanupCommand;
    public $prepareCommand;
    public $runWorkerCommand;
    public $killWorkerCommand;

    // Whether we can send signals to child processes
    // If no, then we will invoke a killWorkerCommand to kill
    // child process
    public $canSignal = false;

    // A named pipe or a file through which the program code is sent
    // to a worker
    public $innerProgramPath = '/program.php';

    public $workerSandboxRoot;

    // If worker failed to initialize or run the code,
    // make a pause of this number of seconds before 
    // starting new one
    public $pauseOnFailure = 3;

    public $maxExecutionTime = 4;

    // How much should we wait for process termination after 
    // kill script was invoked
    public $waitForTerminationTimeout = 15;

    // How much to wait for every script (e.g. prepare script, cleanup script)
    public $scriptExecuteTimeout = 15;

    public $maxOutputBytes = \INF;

    public static function getVmBaseDirectory($sandboxRoot, $slotNumber)
    {
        return $sandboxRoot . '/vm-' . $slotNumber;
    }

    public function getProgramPath($slotNumber)
    {
        return self::getVmBaseDirectory($this->workerSandboxRoot, $slotNumber) . 
            '/' . $this->innerProgramPath;
    }
}
