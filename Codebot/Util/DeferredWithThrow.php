<?php 

namespace Codebot\Util;

use React\Promise\Deferred;

class DeferredWithThrow extends Deferred
{
    private $wrappedPromise;

    public function promise()
    {
        if (null === $this->wrappedPromise) {
            $this->wrappedPromise = new PromiseWithThrow(parent::promise());
        }

        return $this->wrappedPromise;
    }
}
