<?php

namespace Codebot\Worker;

class WorkerTaskResult
{
    const UNKNOWN = -1;

    const KILLED_TIMEOUT = 'timeout';
    const KILLED_TOO_MUCH_OUTPUT = 'too_much_output';
    const KILLED_BY_DESTROY_REQUEST = 'destroy_request';

    /** @var WorkerTask */
    public $task;

    public $exitCode;

    public $exitSignal;

    // If process was killed by suprvisor, will contain kill reason
    public $killReason = null;

    public $executionTime = self::UNKNOWN;

    public $memoryConsumption = self::UNKNOWN;

    public static function createSuccessful(WorkerTask $task, $exitCode, $exitSignal)
    {
        $result = new self;
        $result->task = $task;
        $result->exitCode = $exitCode;
        $result->exitSignal = $exitSignal;

        return $result;
    }

    public static function createForKilled(WorkerTask $task, $killReason)
    {
        $result = new self;
        $result->task = $task;
        $result->killReason = $killReason;
        return $result;
    }

    public function wasKilledOnTooMuchOutput()
    {
        return $this->killReason === self::KILLED_TOO_MUCH_OUTPUT;
    }

    public function wasKilledOnTimeout()
    {
        return $this->killReason === self::KILLED_TIMEOUT;
    }
    
    public function wasKilledBySignal()
    {
        return !!$this->exitSignal;
    }

    public function hasExited()
    {
        return !$this->killReason && !$this->wasKilledBySignal();
    }
}
