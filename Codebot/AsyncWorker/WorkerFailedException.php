<?php

namespace Codebot\Worker;

/**
 * Recoverable worker error that allows to start a new worker
 * instead
 */
class WorkerFailedException extends \Exception
{

}