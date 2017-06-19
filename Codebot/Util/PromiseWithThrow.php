<?php

namespace Codebot\Util;

use React\Promise\ExtendedPromiseInterface;

/**
 * A promise wrapper that throws an excpetion if an underlying promise
 * is rejected but no handlers for rejected value are set. So the
 * information about exception while executing operation doesn't get lost.
 */
class PromiseWithThrow implements ExtendedPromiseInterface
{
    private $promise;

    private $hasRejectHandler;

    public function __construct(ExtendedPromiseInterface $promise)
    {
        $this->promise = $promise;
        $promise->done(null, function ($e) {
            if (!$this->hasRejectHandler) {
                throw new RejectNotHandledException($e);
            }
        });
    }

    /**
     * Wraps a promise with PromiseWithThrow unless it is already wrapped
     *
     * @return ExtendedPromiseInterface
     */
    public static function wrap(ExtendedPromiseInterface $promise)
    {
        // Prevent extra wrappers
        if ($promise instanceof self) {
            return $promise;
        }

        return new self($promise);
    }
    
    
    /**
     * @return PromiseInterface
     */
    public function then(callable $onFulfilled = null, callable $onRejected = null, callable $onProgress = null)
    {
        if ($onRejected) {
            $this->hasRejectHandler = true;
        }

        return self::wrap($this->promise->then($onFulfilled, $onRejected, $onProgress));
    }

    public function done(callable $onFulfilled = null, callable $onRejected = null, callable $onProgress = null)
    {
        if ($onRejected) {
            $this->hasRejectHandler = true;
        }

        return $this->promise->done($onFulfilled, $onRejected, $onProgress);
    }

    /**
     * @return ExtendedPromiseInterface
     */
    public function otherwise(callable $onRejected)
    {
        $this->hasRejectHandler = true;
        return self::wrap($this->promise->otherwise($onRejected));
    }

    /**
     * @return ExtendedPromiseInterface
     */
    public function always(callable $onFulfilledOrRejected)
    {
        $this->hasRejectHandler = true;
        return self::wrap($this->promise->always($onFulfilledOrRejected));
    }

    /**
     * @return ExtendedPromiseInterface
     */
    public function progress(callable $onProgress)
    {
        return self::wrap($this->promise->progress($onProgress));
    }

    /**
     * Installs a handler that allows to check the status of a promise
     * while allowing it to throw an exception when rejected.
     *
     * @return  void 
     */
    public function onCompleted(callable $onCompleted)
    {
        $this->promise->then(function ($value) use ($onCompleted) {
            $onCompleted(null, $value, true);
        }, function ($e) use ($onCompleted) {
            $onCompleted($e, null, false);
        });
    }
}