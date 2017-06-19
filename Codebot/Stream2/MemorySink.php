<?php 

namespace Codebot\Stream2;

use React\Stream\WritableStream;

/**
 * A WritableStreamInterface that collects all written data 
 * in a buffer.
 */
class MemorySink extends WritableStream
{
    private $buffer = '';
    private $limitBytes = \INF;

    function __construct($limitBytes = \INF)
    {
        $this->limitBytes = $limitBytes;    
    }

    public function write($data)
    {
        if ($this->isClosed()) {
            $this->emit('error', [
                new StreamException("Trying to write into a closed buffer"),
                $this
            ]);

            return false;
        }

        $currentSize = $this->getDataSize();
        $addSize = strlen($data);

        if ($currentSize + $addSize <= $this->limitBytes) {
            $this->buffer .= $data;
            return !$this->isBufferFull();
        }

        $leftBytes = $this->limitBytes - $currentSize;
        $this->buffer .= substr($data, 0, $leftBytes);        

        $this->emit('error', [
            new StreamException("Buffer is full, limit is {$this->limitBytes}, left bytes is {$leftBytes} and added data size is {$addSize}, closing buffer"),
            $this
        ]);

        $this->close();
            
        return false;        
    }

    public function getDataSize()
    {
        return strlen($this->buffer);
    }

    public function isBufferFull()
    {
        return strlen($this->buffer) >= $this->limitBytes;
    }
    
    public function getContents()
    {
        return $this->buffer;
    }

    public function isClosed()
    {
        return $this->closed;
    }   
}