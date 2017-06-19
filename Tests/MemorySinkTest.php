<?php 

namespace Tests;

use Codebot\Stream2\MemorySink;
use Evenement\EventEmitter;

class MemorySinkTest extends \PHPUnit\Framework\TestCase
{
    public function testContentIsSaved()
    {
        $errors = [];

        $sink = new MemorySink(1000);
        $this->captureEvents($sink, 'error', $errors);

        $sink->write('Test1');
        $sink->write('Test2');
        $sink->end('Test3');

        $this->assertEquals('Test1Test2Test3', $sink->getContents());
        $this->assertEmpty($errors);
    }

    public function testOverflow()
    {
        $errors = [];
        $sink = new MemorySink(100);
        $longData = str_repeat('a', 200);

        $this->captureEvents($sink, 'error', $errors);
        $sink->write('a');
        $sink->write($longData);

        $stored = $sink->getContents();
        $this->assertTrue(strlen($stored) == 100);

        $this->assertNotEmpty($errors);
    }
    
    private function captureEvents(EventEmitter $source, $event, &$storage)
    {
        $source->on($event, function ($event) use (&$storage) {
            $storage[] = $event;
        });
    }
}