<?php 

namespace Tests\Helper;

use Tests\Helper\TestContainer;

class FileHelper
{
    public static function createTmpDir()
    {
        $dir = '/tmp/guard-test-' . self::generateRandomName(12);
        assert(!file_exists($dir));

        TestContainer::getTestFilesystem()->mkdir($dir, 0777);
        return $dir;
    }

    public static function createTmpFileName()
    {
        $path = '/tmp/guard-test-' . self::generateRandomName(12);
        assert(!file_exists($path));

        return $path;
    }

    public static function cleanupOldTmpFiles()
    {
        $mask = '/tmp/guard-test-*';
        $files = glob($mask);
        $fs = TestContainer::getTestFilesystem();

        foreach ($files as $file) {
            $mtime = filemtime($file);
            $age = microtime(true) - $mtime;
            if ($age > 600) {
                printf("Cleanup old tmp file/dir %s\n", $file);
                $fs->remove($file);
            }
        }
    }

    // public static function createNamedPipe($directory)
    // {
    //     $path = $directory . '/pipe-' . self::generateRandomName(6);
    //     assert(!file_exists($path));
    //     if (!posix_mkfifo($path, 0777)) {
    //         throw new \Exception("Failed to create a fifo pipe at $path");
    //     }

    //     return $path;
    // }

    // /**
    //  * Drops processes listening on a pipe by writing EOF to it. As 
    //  * a pipe can block if nobody is reading, we use non-blocking 
    //  * access to test it.
    //  */
    // public function hangupOnNamedPipe($path)
    // {
    //     if (file_exists($path)) {
    //         // Will not block because of rw+
    //         $fd = fopen($path, 'rw+');
    //         // We will send EOF to readers
    //         fclose($fd);
    //     }
    // }

    private static function generateRandomName($length)
    {
        $name = '';
        $letters = 'abcdefghijklmnopqrstuvwxyz0123456789';

        for ($i=0; $i < $length; $i++) { 
            $index = mt_rand(0, strlen($letters) - 1);
            $name .= $letters{$index};
        }

        return $name;
    }
    
}