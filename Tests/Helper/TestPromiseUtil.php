<?php 

namespace Tests\Helper;

use Codebot\Util\PromiseWithThrow;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class TestPromiseUtil
{
    public static function runLoopWithTimeout(LoopInterface $loop, $timeoutSeconds)
    {
        $timer = null;
        $isTimeout = false;

        $timer = $loop->addTimer($timeoutSeconds, function () use ($loop, &$isTimeout) {
            // $this->logger->warn("Stopping event loop on timeout of $timeout sec.\n");
            $loop->stop();
            $isTimeout = true;
        });

        $loop->run();
    }

    public static function waitForPromise(LoopInterface $loop, $timeout, PromiseInterface $promise)
    {
        $timer = null;
        $isTimeout = false;

        $timer = $loop->addTimer($timeout, function () use ($loop, &$isTimeout) {
            // $this->logger->warn("Stopping event loop on timeout of $timeout sec.\n");
            $loop->stop();
            $isTimeout = true;
        });

        $onDone = function () use ($loop, &$timer, &$isTimeout) {
            $loop->cancelTimer($timer);
            $timer = null;
            $isTimeout = false;
        };

        $promise->then($onDone, $onDone);

        $loop->run();
        return $isTimeout;
    }
    
    public static function handleRejection(PromiseWithThrow $promise)
    {
        $promise->done(function () {}, function () {});
    }
}