<?php

namespace Codebot\Util;

class RejectNotHandledException extends \Exception
{
    function __construct(\Exception $previous) 
    {
        parent::__construct(
            "Rejection not handled: {$previous->getMessage()}",
            0,
            $previous
        );
    }
}