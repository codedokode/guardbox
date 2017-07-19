<?php

namespace Codebot\AsyncWorker;

use React\Stream\Stream;

/**
 * A task that is necessary to run in a sandbox
 */
class WorkerTask
{
    public $uniqueId;

    /** 
     * A program code that is executed
     */
    public $program;

    /**
     * @var Stream|null if null, stdout data will not be saved
     *      (but stdout pipe will accept them)
     */
    public $stdoutSink;

    /**
     * @var Stream|null if null, stderr data will not be saved 
     */
    public $stderrSink;

    /**
     * @var string|null if null, stdin pipe will be closed
     */
    public $stdin;
}
