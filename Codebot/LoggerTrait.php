<?php

namespace Codebot;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait LoggerTrait
{
    private $logger;
    private static $loggerDefault;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    protected function getLogger()
    {
        return $this->logger ? $this->logger : 
            (self::$loggerDefault ? self::$loggerDefault : 
                (self::$loggerDefault = new NullLogger));
    }
}