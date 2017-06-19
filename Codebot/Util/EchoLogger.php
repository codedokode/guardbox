<?php

namespace Codebot\Util;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Outputs all log messages to stdout, adding optional prefix.
 */
class EchoLogger extends AbstractLogger
{
    private $prefix;

    private $logPrefixesPerLevel = [
        LogLevel::INFO      => 'info: ',
        LogLevel::NOTICE    => 'notice: ',
        LogLevel::DEBUG     => 'debug: ',
        LogLevel::WARNING   => 'warning: ',
        LogLevel::ALERT     => 'alert: ',
        LogLevel::ERROR     => 'error: ',
        LogLevel::EMERGENCY => 'emergency: ',
        LogLevel::CRITICAL  => 'critical: ',
    ];

    public function __construct($prefix = '') 
    {
        $this->prefix = $prefix;
    }    

    public function log($level, $message, array $context = array())
    {
        $message = $this->formatMessage($message, $context);
        $logPrefix = $this->logPrefixesPerLevel[$level];
        echo $this->prefix . $logPrefix . $message . "\n";
    }

    private function formatMessage($message, array $context)
    {
        return preg_replace_callback("/\{([a-zA-Z0-9_\-]+)\}/", function ($m) use ($context) {
            if (!array_key_exists($m[1], $context)) {
                throw new \Exception("No key '{$m[1]}' in context");
            }

            return $context[$m[1]];
        }, $message);
    }    
}