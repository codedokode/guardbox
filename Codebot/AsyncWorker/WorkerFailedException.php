<?php

namespace Codebot\AsyncWorker;

/**
 * Recoverable worker error that allows to start a new worker
 * instead
 */
class WorkerFailedException extends \Exception
{

}