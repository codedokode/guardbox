<?php 

namespace Tests\Stream2;

use Codebot\Stream2\MemorySink;
use Codebot\Stream2\StreamWrapper;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Stream\Buffer;
use React\Stream\ReadableStreamInterface;
use React\Stream\Stream;
use Tests\Helper\FileHelper;
use Tests\Helper\PreprogrammedStream;
use Tests\Helper\TestPromiseUtil;

class StreamWrapperTest extends \PHPUnit\Framework\TestCase
{
    /** @var LoopInterface */    
    private $loop;

    private $tmpFiles = [];
    private $openedFiles = [];

    public function setUp()
    {
        $this->loop = new StreamSelectLoop;   
    }

    public static function setUpBeforeClass()
    {
        FileHelper::cleanupOldTmpFiles();
    }

    public function tearDown()
    {
        foreach ($this->openedFiles as $fd) {
            if (is_resource($fd)) {
                fclose($fd);
            }
        }

        foreach ($this->tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function getReadableFiles()
    {
        $text = str_repeat('ABC', 1000) . str_repeat('DEF', 1000);
        $tmpFile = $this->createTmpFileName();

        return [
            // path, createFile, isEndless, contents
            '/dev/null' => ['/dev/null', false, false, ''],
            '/dev/zero' => ['/dev/zero', false, true, ''],
            'text file' => [$tmpFile, true, false, $text]
        ];

        // Cannot check read error because in PHP fread() ignores 
        // most errors and reading from files like /proc/self/mem 
        // gives just EOF
    }

    /**
     * @medium
     * @dataProvider getReadableFiles
     */
    public function testDataReadFromFileAndStreamStatus($path, $createFile, $isDevZero, $contents)
    {
        $calls = 0;
        $buffer = '';

        if ($createFile) {
            file_put_contents($path, $contents);
        }

        $stream = $this->openForRead($path);
        $stream->onDataRead(function ($data) use (&$calls, &$buffer) {

            $this->assertInternalType('string', $data);

            $calls++;

            // Limit buffer size in order to not run out of memory when 
            // reading /dev/zero
            if (strlen($buffer) < 100000) {
                $buffer .= $data;
            }
        });

        $this->runLoopFor(1);

        $this->assertFalse($stream->isCancelled());
        $this->assertFalse($stream->isClosedOnError());

        if (!$isDevZero) {
            $this->assertEquals($contents, $buffer);
            $this->assertTrue($stream->isEof());
            $this->assertFalse($stream->isReadable());
        } else {
            $this->assertFalse($stream->isEof());
            $this->assertTrue($stream->isReadable());

            // Must be called more than once
            $this->assertGreaterThan(1, $calls);
            $this->assertGreaterThan(1, strlen($buffer));

            // Buffer must contain only zeros
            $containsNonZero = preg_match("/([^\\x00])/", $buffer, $m);
            if ($containsNonZero) {
                $code = ord($m[1]);
                $this->assertTrue(false, "Buffer contains non-zero byte $code");
            }
        }
    }
    
    /**
     * @medium
     * @dataProvider getReadableFiles
     */
    public function testEventsSequenceWhenReadingFile($path, $createFile, $isDevZero, $contents)
    {
        /*
            Events must happen in the following order: 
            - normal file: READ_DATA, COMPLETED, END, 
            - /dev/zero: READ_DATA only
         */
        
        if ($createFile) {
            file_put_contents($path, $contents);
        }

        $reader = $this->openForRead($path);
        $expectedReason = $isDevZero ? null : StreamWrapper::REASON_EOF;
        $this->checkEventSequence($reader, $isDevZero, $expectedReason);     
    }

    public function getReadEvents()
    {
        return [
            'end'   => [['end'], StreamWrapper::REASON_EOF],
            'close' => [['close'], StreamWrapper::REASON_CANCELLED],
            'error' => [['error', new \Exception('Test')], StreamWrapper::REASON_ERROR]
        ];
    }

    /**
     * @medium
     * @dataProvider getReadEvents
     */
    public function testCompleteEventIsReportedOnce($endEvent, $expectedReason)
    {
        $events = [
            ['data', 'test1'],
            ['data', 'test2'],
            $endEvent,
            ['end'],
            ['close'],
            ['error', new \Exception('Test error')],
            ['end'],
            // ['data', 'test3'],
            ['close'],
            ['error', new \Exception('Test error')]
        ];

        $testStream = new PreprogrammedStream($events, $this->loop);
        $wrapper = StreamWrapper::wrapReadOnly($testStream);

        // If we don't read data or handle errors, there will be an exception
        $wrapper->onDataRead(function () {});
        $wrapper->catchError(function () {});

        $this->checkEventSequence($wrapper, false, $expectedReason);
    }

    private function checkEventSequence(StreamWrapper $wrapper, $isDevZero, $expectedReason)
    {
        $wrapper->onCompleted(function ($reason) use ($expectedReason) {
            $this->assertEquals($expectedReason, $reason);
        });

        $events = [];
        $this->captureEvents($wrapper, $events);

        $this->runLoopFor(1);

        $completedCount = count(array_keys($events, StreamWrapper::EVENT_COMPLETED));
        $endCount = count(array_keys($events, StreamWrapper::EVENT_END));
        $errorCount = count(array_keys($events, StreamWrapper::EVENT_ERROR));
        $drainCount = count(array_keys($events, StreamWrapper::EVENT_DRAIN));

        if ($isDevZero) {
            $this->assertEquals(0, $completedCount);
        } else {
            $this->assertEquals(1, $completedCount);
        }

        $this->assertEquals(0, $drainCount);

        $this->assertFalse($this->areEventsAfter(
            $events, 
            StreamWrapper::EVENT_ERROR, 
            StreamWrapper::EVENT_DATA_READ
        ));

        $this->assertFalse($this->areEventsAfter(
            $events, 
            StreamWrapper::EVENT_END, 
            StreamWrapper::EVENT_DATA_READ
        ));

        $this->assertFalse($this->areEventsAfter(
            $events, 
            StreamWrapper::EVENT_COMPLETED, 
            StreamWrapper::EVENT_DATA_READ
        ));

        if ($expectedReason === StreamWrapper::REASON_ERROR) {
            $this->assertEquals(1, $errorCount);
        } else {
            $this->assertEquals(0, $errorCount);
        }

        if ($expectedReason === StreamWrapper::REASON_EOF) {
            $this->assertEquals(1, $endCount);
        } else {
            $this->assertEquals(0, $endCount);
        }
    }

    public function getWritableFiles()
    {
        $writeSizes = [100, 100000];
        $tmpFile = $this->createTmpFileName();
        $files = [];

        foreach ($writeSizes as $writeSize) {
            $files = array_merge($files, [
                ['/dev/null', $writeSize, false, false],
                ['/dev/full', $writeSize, true, false],
                [$tmpFile, $writeSize, false, true]
            ]);
        }

        return $files;
    }

    /**
     * @medium
     * @dataProvider getWritableFiles
     */
    public function testWritingToFile($path, $writeSize, $expectError, $rereadAgain)
    {
        $data = str_repeat('ABC', 1000) . str_repeat('DEF', 1000);

        $stream = $this->createWritableStream($path);

        $stream->onCompleted(function ($reason) use ($expectError) {
            if ($expectError) {
                $this->assertEquals(StreamWrapper::REASON_ERROR, $reason);
            } else {
                $this->assertEquals(StreamWrapper::REASON_EOF, $reason);
            }
        });

        $stream->catchError(function ($e) use ($expectError) {
            if (!$expectError) {
                $this->assertTrue(false, "onError should not be called");
            }
        });

        $this->writeDataToStream($stream, $data, $writeSize);

        $this->runLoopFor(3, $stream);

        if (!$expectError) {
            $this->assertTrue($stream->isEof());
            $this->assertFalse($stream->isClosedOnError());
        } else {
            $this->assertFalse($stream->isEof());
            $this->assertTrue($stream->isClosedOnError());
        }

        $this->assertFalse($stream->isWritable());

        if ($rereadAgain) {
            $readData = file_get_contents($path);
            $this->assertNotFalse($data);
            $this->assertEquals($data, $readData);
        }
    }
    
    /**
     * @dataProvider getWritableFiles
     * @medium
     */
    public function testWriteEventsSequence($path, $writeSize, $expectError, $readAgain)
    {
        $data = str_repeat('ABC', 1000) . str_repeat('DEF', 1000);

        $stream = $this->createWritableStream($path);
        $events = [];
        $this->captureEvents($stream, $events);

        $this->writeDataToStream($stream, $data, $writeSize);
        $this->runLoopFor(2);

        $this->assertNotContains(StreamWrapper::EVENT_DATA_READ, $events);

        $errorCount = count(array_keys($events, StreamWrapper::EVENT_ERROR));
        $eofCount = count(array_keys($events, StreamWrapper::EVENT_END));
        $completedCount = count(array_keys($events, StreamWrapper::EVENT_COMPLETED));

        $this->assertEquals(1, $completedCount);

        if ($expectError) {
            $this->assertEquals(1, $errorCount);
            $this->assertEquals(0, $eofCount);
        } else {
            $this->assertEquals(0, $errorCount);
            $this->assertEquals(1, $eofCount);
        }

        $this->assertFalse($this->areEventsAfter(
            $events, 
            StreamWrapper::EVENT_COMPLETED,
            StreamWrapper::EVENT_DRAIN
        ));

        $this->assertFalse($this->areEventsAfter(
            $events, 
            StreamWrapper::EVENT_END,
            StreamWrapper::EVENT_DRAIN
        ));

        $this->assertFalse($this->areEventsAfter(
            $events, 
            StreamWrapper::EVENT_ERROR,
            StreamWrapper::EVENT_DRAIN
        ));
    }

    private function writeDataToStream(StreamWrapper $stream, $data, $blockSize)
    {
        $blocks = str_split($data, $blockSize);

        $writeOut = function () use (&$blocks, $stream) {

            $canWriteMore = true;

            while ($blocks && $canWriteMore) {
                $block = array_shift($blocks);
                $canWriteMore = $stream->write($block);
            };

            if (!$blocks) {                
                $stream->end();
            }
        };

        $stream->onDrain(function () use ($writeOut) {
            $writeOut();
        });

        $writeOut();
    }

    public function testPiping()
    {
        $events = [
            ['data', 'abc'],
            ['data', 'def'],
            ['end']
        ];

        $sourceStream = new PreprogrammedStream($events, $this->loop);
        $reader = StreamWrapper::wrapReadOnly($sourceStream);
        $writerStream = new MemorySink();
        $writer = $reader->pipe($writerStream);

        $this->runLoopFor(3, $writer);

        $this->assertTrue($reader->isEof());
        $this->assertTrue($writer->isEof());
        $this->assertEquals('abcdef', $writerStream->getContents());
    }

    // public function testParallelReadWrite()
    // {
        
    // }

    /**
     * @medium
     */
    public function testExceptionThrownIfNotHandled()
    {
        $events = [
            ['error', new \RuntimeException('Test')]
        ];

        $sourceStream = new PreprogrammedStream($events, $this->loop);
        $reader = StreamWrapper::wrapReadOnly($sourceStream);
        
        $this->setExpectedException('RuntimeException');

        $this->runLoopFor(2, $reader);
    }

    /**
     * @medium
     */
    public function testExceptionThrownIfNoReadHandlerSet()
    {
        $events = [
            ['data', 'test']
        ];

        $sourceStream = new PreprogrammedStream($events, $this->loop);
        $reader = StreamWrapper::wrapReadOnly($sourceStream);
        
        $this->setExpectedException('Codebot\Stream2\StreamException');

        $this->runLoopFor(2, $reader);
    }

    public function testCannotWriteToClosedStream()
    {
        $writerStream = new MemorySink();
        $writer = StreamWrapper::wrapWriteOnly($writerStream);
        $writerStream->close();

        $this->setExpectedException('Codebot\Stream2\StreamException');
        $writer->write('test');
    }
 
    private function runLoopFor($timeoutSeconds, StreamWrapper $waitFor = null)
    {
        $deferred = new Deferred;
        if ($waitFor) {
            $waitFor->onCompleted(function () use ($deferred) {
                // Stop waiting
                $deferred->resolve();
            });
        }

        TestPromiseUtil::waitForPromise($this->loop, $timeoutSeconds, $deferred->promise());
    }    

    private function createTmpFileName()
    {
        $path = FileHelper::createTmpFileName();
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * @return StreamWrapper
     */
    private function openForRead($path)
    {
        $fd = fopen($path, 'rb');
        $this->openedFiles[] = $fd;
        $readableStream = new Stream($fd, $this->loop);
        $wrapper = StreamWrapper::wrapReadOnly($readableStream);

        return $wrapper;
    }

    private function createWritableStream($path)
    {
        $fd = fopen($path, 'wb');
        $this->openedFiles[] = $fd;
        $stream = new Buffer($fd, $this->loop);
        $wrapper = StreamWrapper::wrapWriteOnly($stream);

        return $wrapper;
    }

    private function captureEvents(StreamWrapper $stream, array &$events)
    {
        $recordEvent = function ($event) use (&$events) {
            return function() use (&$events, $event) {
                $events[] = $event;
            };
        };

        $stream->onEnd($recordEvent(StreamWrapper::EVENT_END));
        $stream->onCompleted($recordEvent(StreamWrapper::EVENT_COMPLETED));
        $stream->onDrain($recordEvent(StreamWrapper::EVENT_DRAIN));
        $stream->catchError($recordEvent(StreamWrapper::EVENT_ERROR));
        $stream->onDataRead($recordEvent(StreamWrapper::EVENT_DATA_READ));
    }

    /**
     * Returns true if there is a $secondEvent after $firstEvent in $events array
     */
    private function areEventsAfter(array $events, $firstEvent, $secondEvent)
    {
        $sawFirstEvent = false;
        foreach ($events as $event) {
            if ($event === $firstEvent) {
                $sawFirstEvent = true;
                continue;
            }

            if ($event === $secondEvent && $sawFirstEvent) {
                return true;
            }
        }

        return false;
    }

    // public function testWriteIn2StepsAndEof()
    // {
        
    // }

    // public function testFilteringStream()
    // {
     
    // }                 
}