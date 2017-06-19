<?php 

namespace Tests\Helper;

use Codebot\Util\EchoLogger;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Filesystem\Filesystem;

class TestContainer
{
    private static $fs;
    private static $logger;

    public static function getTestFilesystem()
    {
        if (!self::$fs) {
            self::$fs = new Filesystem;
        }

        return self::$fs;
    }
    
    public static function getLogger()
    {
        if (!self::$logger) {
            self::$logger = new EchoLogger('codebot-test: ');
        }

        return self::$logger;
    }
}