<?php 

namespace Tests\Helper;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Logger that captures all received messages
 */
class CapturingLogger extends AbstractLogger
{
    private $messages = [];

    /**
     * @var LoggerInterface 
     */
    private $nextLogger;

    public function setNextLogger(LoggerInterface $logger = null)
    {
        $this->nextLogger = $logger;
    }

    public function log($level, $message, array $context = array())
    {
        $this->messages[] = $this->formatMessage($message, $context);
        if ($this->nextLogger) {
            $this->nextLogger->log($level, $message, $context);
        }
    }

    public function getMessages()
    {
        return $this->messages;
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