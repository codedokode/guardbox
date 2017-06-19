<?php 

namespace Tests;

// use Codebot\Stream2\StreamWrapper;
use React\EventLoop\StreamSelectLoop;
use React\Stream\Buffer;
use React\Stream\Stream;

class ReactStreamTest extends \PHPUnit\Framework\TestCase
{
    public function testReadFromDevZeroDoesNotHang()
    {
        $loop = new StreamSelectLoop;
        $fd = fopen('/dev/zero', 'r');
        $reader = new Stream($fd, $loop);
        // $wrapper = StreamWrapper::wrapReadOnly($reader);
        // $wrapper->onDataRead(function () {});

        $bytes = 0;

        $reader->on('data', function ($data) use (&$bytes) {
            $bytes += strlen($data);
            // printf("%d\n", $bytes);
        });

        $startTime = microtime(true);

        $timeoutSeconds = 1;
        $timer = $loop->addTimer($timeoutSeconds, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        $passed = microtime(true) - $startTime;
        printf("%d bytes read in %.3f seconds\n", $bytes, $passed);

        $this->assertLessThan(3, $passed);
    }

    public function testWriteToDevFullEmitsError()
    {
        $loop = new StreamSelectLoop;
        $fd = fopen('/dev/full', 'w');
        $writer = new Buffer($fd, $loop);

        $errorReported = false;

        $writer->on('error', function ($e) use (&$errorReported) {
            $errorReported = true;
            printf("Error: %s\n", $e->getMessage());
        });

        $writer->on('end', function () {
            printf("End\n");
        });

        $writer->on('close', function () {
            printf("Close\n");
        });

        $writer->write('test');
        $writer->end();

        $loop->run();

        $this->assertTrue($errorReported);
    }
}