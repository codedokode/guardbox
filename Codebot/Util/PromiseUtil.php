<?php

namespace Codebot\Util;

use Codebot\Util\DeferredWithThrow;
use Codebot\Util\PromiseWithThrow;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\FulfilledPromise;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\Stream;

/**
 * Helper functions for promises
 */
class PromiseUtil
{
    /**
     * Resolves or rejects into whichever promise resolves or rejects first
     *
     * @return ExtendedPromiseInterface
     */
    public static function first(array $promises)
    {
        return new Promise(function ($resolve, $reject, $notify) use ($promises) {
            foreach ($promises as $promise) {
                $promise->done(function ($result) use ($resolve) {
                    $resolve($result);
                }, function ($e) use ($reject) {
                    $reject($e);
                });
            }
        });
    }

    /**
     * all() doesn't rejects on first failure
     */
    public static function allSuccess(array $promises)
    {
        $promises = array_values($promises);
        $results = [];
        
        return new Promise(function ($resolve, $reject, $notify) use (&$results, $promises) {

            foreach ($promises as $key => $promise) {
                $promise->then(function ($value) use ($key, &$results, $promises, $resolve) {
                    $results[$key] = $value;

                    if (count($results) >= count($promises)) {
                        $resolve($results);
                    }

                }, function ($e) use ($reject) {
                    $reject($e);
                });
            }
        });                
    }
    

    /**
     * Returns a promise that is resolved when all of given promises
     * are either resolved or rejected.
     *
     * @return ExtendedPromiseInterface
     */
    public static function allCompleted(array $promises)
    {
        $completed = 0;

        return new Promise(function ($resolve, $reject, $notify) use (&$completed, $promises) {

            $onCompleted = function ($value) use (&$completed, $promises, $resolve) {
                $completed++;
                if ($completed >= count($promises)) {
                    $resolve(true);
                }
            };

            foreach ($promises as $promise) {
                $promise->then($onCompleted, $onCompleted);
            }
        });        
    }
    

    /**
     * Returns a promise that is resolved after writing a file
     * successfully or rejected if the operation failes.
     *
     * @return ExtendedPromiseInterface 
     */
    public static function writeFileAsync($path, $contents, LoopInterface $loop)
    {
        $fd = @fopen($path, 'wb');

        if (!$fd) {
            $error = error_get_last();
            return new RejectedPromise(new \Exception(
                "Failed to open {$path} for write: {$error['message']}"
            ));
        }

        $stream = new Stream($fd, $loop);
        $result = new DeferredWithThrow(function ($resolve, $reject, $notify) use ($stream) {
            // On cancel close stream and reject self
            $stream->close();
            $reject(new \Exception("Writing was cancelled"));
        });

        $stream->on('error', function ($e) use ($result) {
            $result->reject($e);
        });

        $stream->on('end', function () use ($result) {
            $result->resolve(true);
        });

        $stream->end($contents);

        return $result->promise();
    }    

    // /**
    //  * Returns a promise that is resolved with $promise value. If 
    //  * $promise is rejected, nothing happens.
    //  *
    //  * Stops reject propagation.
    //  */
    // public static function ignoreReject(ExtendedPromiseInterface $promise)
    // {
    //     return new Promise(function ($resolve, $reject, $notify) use ($promise) {
    //         $promise->done($resolve);
    //         $promise->progress($notify);
    //     }, function () use ($promise) {
    //         // cancel callback
    //         $promise->cancel();
    //     });
    // }

    // /**
    //  * Rejects if $timeout resolves before $promise.
    //  * Otherwise resolves with $promise value
    //  *
    //  * $timeout is cancelled if $promise is cancelled or resolved.
    //  */
    // public static function resolveBeforeTimeout(ExtendedPromiseInterface $promise, ExtendedPromiseInterface $timeout)
    // {
    //     return new Promise(function ($resolve, $reject, $notify) use ($promise, $timeout)) {

    //         $timeout->done(function () use ($reject) {
    //             // If promise was alredy resolved, this will be ignored
    //             $reject(new PromiseTimeoutException);
    //         });

    //         $promise->done(function ($value) use ($timeout, $resolve) {
    //             $timeout->cancel();
    //             $resolve($value);
    //         });

    //         // propagate progress
    //         $promise->progress($notify);
    //     }, function () use ($promise, $timeout) {
    //         // Propagate cancel signal
    //         $promise->cancel();
    //         $timeout->cancel();
    //     });
    // }

    // /**
    //  * Creates a cancellable promise that is resolved after a given timeout
    //  * after $startAfter resolves.
    //  */
    // public static function createTimeoutPromise(
    //     LoopInterface $loop, 
    //     $timeoutSeconds, 
    //     PromiseInterface $startAfter = null
    // ) {
    //     $cancelled = false;
    //     $timerIdDeferred = new Deferred;

    //     if (!$startAfter) {
    //         // If start after not given, start immediately
    //         $startAfter = new FulfilledPromise();
    //     }

    //     $deferred = new Deferred(function () use ($timerIdDeferred, &$cancelled) {
    //         $cancelled = true;
    //         $timerIdDeferred->promise()->done(function ($timer) {
    //             $this->loop->cancelTimer($timer);
    //         });
    //     });

    //     $startAfter->then(function () use ($loop, $timeoutSeconds, $deferred, $timerIdDeferred, &$cancelled) {
    //         if ($cancelled) {
    //             return;
    //         }

    //         $timer = $loop->addTimer($timeout, function () use ($deferred) {
    //             $deferred->resolve(null);
    //         });

    //         $timerIdDeferred->resolve($timer);
    //     });        

    //     return $deferred->promise();
    // }

    public static function peekValue(PromiseInterface $promise)
    {
        $result = null;

        if ($promise instanceof PromiseWithThrow) {
            $promise->onCompleted(function ($e, $value) use (&$result) {
                $result = $value;
            });
        } else {
            $promise->then(function ($value) use (&$result) {
                $result = $value;
            });
        }

        return $result;
    }
    
    public static function getRejectReason(PromiseInterface $promise)
    {
        $reason = null;

        if ($promise instanceof PromiseWithThrow) {
            $promise->onCompleted(function ($e, $value) use (&$reason) {
                $reason = $e;
            });
        } else {
            $promise->then(null, function ($e) use (&$reason) {
                $reason = $e;
            });
        }

        return $reason;
    }

    private static function peekStatus(PromiseInterface $promise)
    {        
        $isResolved = false;
        $isRejected = false;

        if ($promise instanceof PromiseWithThrow) {
            $promise->onCompleted(function ($e, $value, $status) 
                use (&$reason, &$isResolved, &$isRejected) {

                if ($status) {
                    $isResolved = true;
                } else {
                    $isRejected = true;
                }
            });
        } else {
            $promise->then(function () use (&$isResolved) {
                $isResolved = true;
            }, function ($e) use (&$isRejected) {
                $isRejected = true;
            });
        }

        return [$isResolved, $isRejected];
    }
    
    public static function isCompleted(PromiseInterface $promise)
    {
        list($isRes, $isRej) = self::peekStatus($promise);
        return $isRes || $isRej;
    }

    public static function isResolved(PromiseInterface $promise)
    {
        list($isRes, $isRej) = self::peekStatus($promise);
        return $isRes;
    }
    
    public static function isRejected(PromiseInterface $promise)
    {
        list($isRes, $isRej) = self::peekStatus($promise);
        return $isRej;
    }

    public static function throwIfRejected(PromiseInterface $promise)
    {
        $e = self::getRejectReason($promise);
        if ($e) {
            throw $e;
        }
    }

    public static function debugPromise(PromiseInterface $promise, $name)
    {
        if ($promise instanceof PromiseWithThrow) {
            $promise->onCompleted(function ($e, $value, $status) use ($name) {
                if ($status) {
                    printf(
                        "%s: %s is resolved to value of type %s\n", 
                        date('H:i:s'),
                        $name,
                        gettype($value)
                    );
                } else {
                    printf(
                        "%s: %s is rejected: %s\n",
                        date('H:i:s'),
                        $name,
                        $e->getMessage()
                    );
                }
            });
        } else {            
            $promise->then(function ($value) use ($name) {
                printf(
                    "%s: %s is resolved to value of type %s\n", 
                    date('H:i:s'),
                    $name,
                    gettype($value)
                );
            }, function ($e) use ($name) {
                printf(
                    "%s: %s is rejected: %s\n",
                    date('H:i:s'),
                    $name,
                    $e->getMessage()
                );
            });
        }
    }
}