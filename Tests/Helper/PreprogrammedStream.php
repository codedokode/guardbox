<?php 

namespace Tests\Helper;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableStream;

/**
 * A stream class that emits preprogrammed events.
 */
class PreprogrammedStream extends ReadableStream
{
    private $events;

    /** @var LoopInterface */
    private $loop;

    private $timer;

    public function __construct(array $events, LoopInterface $loop)
    {
        // parent::__construct();
        $this->events = $events;
        $this->loop = $loop;
        $this->resume();
    }

    public function resume()
    {
        if (!$this->timer) {
            $this->timer = $this->loop->addPeriodicTimer(0.001, [$this, 'handleTick']);
        }
    }

    public function pause()
    {
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }
    
    public function close()
    {
        $this->pause();
        parent::close();
    }
    
    public function handleTick()
    {
        if (!$this->events) {
            $this->pause();
            return;
        }

        $eventArgs = array_shift($this->events);
        $eventName = array_shift($eventArgs);

        $this->emit($eventName, $eventArgs);
    }
}