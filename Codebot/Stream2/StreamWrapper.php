<?php 

namespace Codebot\Stream2;

use Evenement\EventEmitter;
use React\Stream\Buffer;
use React\Stream\ReadableStreamInterface;
use React\Stream\StreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * A wrapper around React\Stream\{ReadableStreamInterface, WritableStreamInterface,
 *     DuplexStreamInterface}
 *
 * Changes are:
 *
 * - any error closes stream
 * - an exception is thrown if error is not handled
 *     (so it doesn't go unnoticed)
 * - an exception is thrown if data were read but not handled
 *     (prevents losing data if event handler is attached too late)
 * - an exception is thrown when trying to write to a closed stream
 *     (prevents losing data)
 * - it is now easy to detect the reason why the stream was closed
 *      (because of error, because of EOF or because close() was called)
 * - onCompleted() event was added that is emitted when stream is closed 
 *     for any reason (EOF, error or cancelled)
 * - pipe() method simplified
 * - close() renamed to cancel()
 * - writing to a closed stream, pausing non-readable stream, etc cause 
 *     logic exceptions
 *
 *  Event handlers are called in this order: 
 *
 *  - EOF when reading data: onDataRead($eof=true), onCompleted(REASON_EOF), onEnd()
 *  - all data written after calling end(): onCompleted(REASON_EOF), onEnd()
 *  - cancel() called: onCompleted(REASON_CANCELLED)
 *  - error when reading/writing data: onCompleted(REAON_ERROR),onError()
 */
class StreamWrapper
{
    const REASON_EOF = 'reason_eof';
    const REASON_ERROR = 'reason_error';
    const REASON_CANCELLED = 'reason_cancelled';

    const EVENT_ERROR = 'error';
    const EVENT_DATA_READ = 'data_read';
    const EVENT_DRAIN = 'drain';
    const EVENT_COMPLETED = 'completed';
    const EVENT_END = 'end';

    /** @var EventEmitter */
    private $events;

    /** @var StreamInterface Underlying readable or writable or duplex stream */
    private $sourceStream;

    private $isReadable = true;
    private $isWritable = true;

    private $closeReason;

    public static function wrapDuplex(DuplexStreamInterface $stream)
    {
        if ($stream instanceof self) {
            return $strem;
        }

        return new self($stream, true, true);
    }

    public static function wrapReadOnly(ReadableStreamInterface $stream)
    {
        if ($stream instanceof self) {
            return $strem;
        }

        return new self($stream, true, false);
    }

    public static function wrapWriteOnly(WritableStreamInterface $stream)
    {
        if ($stream instanceof self) {
            return $strem;
        }

        return new self($stream, false, true);
    }

    private function __construct($stream, $canRead, $canWrite)
    {
        if ($stream instanceof self) {
            throw new \InvalidArumentException("Cannot wrap self");
        }

        $this->sourceStream = $stream;
        $this->isReadable = $canRead && $stream->isReadable();
        $this->isWritable = $canWrite && $stream->isWritable();
        $this->events = new EventEmitter;

        if ($this->isClosed()) {
            throw new \InvalidArumentException("Underlying stream is closed");
        }

        if ($this->isWritable) {
            $stream->on('drain', function () {
                $this->events->emit(self::EVENT_DRAIN);
            });
        }

        $stream->on('error', function ($error) {

            if ($this->isClosed()) {
                return;
            }
            
            $this->closeOnError($error);
        });

        if ($this->isReadable) {
            $stream->on('data', function ($data) use ($stream) {

                // Ignore empty reads
                if ($data === '') {
                    return;
                }

                $haveListeners = $this->events->listeners(self::EVENT_DATA_READ);
                if (!$haveListeners) {
                    throw new StreamException(
                        sprintf(
                            "No data read listeners set, read data were lost (%d bytes)", 
                            strlen($data)
                    ));
                }

                $this->events->emit(self::EVENT_DATA_READ, [$data /* , false */]);
            });
        }

        $stream->on('end', function () {

            if ($this->isClosed()) {
                return;
            }

            $this->reportCloseOnEof();
        });

        $stream->on('close', function () {

            if ($this->isClosed()) {
                return;
            }
            
            // react/stream v0.5's Buffer reports EOF as close, so there is no way
            // to distinguish between EOF and close() call
            if ($this->sourceStream instanceof Buffer) {
                $this->reportCloseOnEof();
            } else {
                $this->reportCloseOnCancelled();
            }
        });
    }

    private function isClosed()
    {
        return !$this->isReadable && !$this->isWritable;
    }

    public function isCancelled()
    {
        return $this->closeReason === self::REASON_CANCELLED;
    }
    
    public function isClosedOnError()
    {
        return $this->closeReason === self::REASON_ERROR;
    }
    
    public function isEof()
    {
        return $this->closeReason === self::REASON_EOF;
    }

    public function isReadable()
    {
        return $this->isReadable;
    }

    public function pause()
    {
        if (!$this->isReadable) {
            throw new StreamExeption("Can only pause opened readable stream");
        }

        $this->sourceStream->pause();
    }

    public function resume()
    {
        if (!$this->isReadable) {
            throw new StreamExeption("Can only resume opened readable stream");
        }

        $this->sourceStream->resume();
    }

    /**
     * Pipes data to a writable stream.
     *
     * Piping to a non-writable stream causes an error.
     * If the target stream was closed while piping data, source stream is 
     * closed with error.
     */
    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        if (!$this->isReadable) {
            throw new StreamExeption("Can only pipe opened readable stream");
        }

        $target = self::wrapWriteOnly($dest);
        $closeTarget = true;

        if (array_key_exists('end', $options) && !$options['end']) {
            $closeTarget = false;
        }

        $this->onDataRead(function ($data /* , $isEof */) use ($target) {

            // Do not raise error if source stream is closed 
            if (!$target->isWritable()) {
                if ($data !== '') {
                    $this->closeWithError(
                        new StreamException("Trying to pipe data to a closed stream")
                    );
                }
                
                return;
            }

            /* if (!$isEof) { */
                $canWriteMore = $target->write($data);
            /* } else {
                $canWriteMore = $target->end($data);
            } */

            if (!$canWriteMore) {
                $this->pause();
            }
        });

        $target->onDrain(function () {
            // If the source stream is closed, ignore drain event
            if ($this->isReadable()) {
                $this->resume();
            }
        });

        if ($closeTarget) {
            $this->onCompleted(function ($reason) use ($target) {
                $target->end();
            });
        }

        return $target;
    }

    public function isWritable()
    {
        return $this->isWritable;
    }

    public function write($data)
    {
        if (!$this->isWritable) {
            throw new StreamException("Cannot write to a non-writable stream");
        }

        // This can happen if someone has called end() on an underlying stream
        if (!$this->sourceStream->isWritable()) {
            throw new StreamException("Underlying stream was closed unexpectedly");
        }

        return $this->sourceStream->write($data);
    }

    public function end($data = null)
    {
        if (!$this->isWritable) {
            throw new \LogicException("Cannot write to a non-writable stream");
        }

        $this->sourceStream->end($data);
    }

    public function close()
    {
        $this->cancel();
    }
    
    public function cancel()
    {
        if ($this->isClosed()) {
            throw new \LogicException("Cannot cancel a closed stream");
        }

        // Should emit necessary events
        $this->sourceStream->close();
    }

    /**
     * Sets a handler that would be called if an read or write error 
     * occurs. If no such handler is set, the error is thrown
     * as an exception.
     *
     * @param callable $handler function (\Exception $e) {}
     */
    public function catchError(callable $handler)
    {
        if ($this->isClosed()) {
            throw new \LogicException("Too late to set error handler on closed stream");
        }

        $this->events->on(self::EVENT_ERROR, $handler);
    }

    /**
     * Sets a handler that would be called when data are read from 
     * an underlying stream or when EOF occurs. 
     *
     * @param callable $handler function (string $data, bool $isEof) {}
     */
    public function onDataRead(callable $handler)
    {
        if ($this->isClosed()) {
            throw new \LogicException("Too late to set read handler on closed stream");
        }

        $this->events->on(self::EVENT_DATA_READ, $handler);
    }

    /**
     * Sets a handler that is called when the buffer is drained and 
     * can accept more data to be written.
     *
     * @param callable $handler function () {}
     */
    public function onDrain(callable $handler)
    {
        if ($this->isClosed()) {
            throw new \LogicException("Too late to set drain handler on closed stream");
        }

        $this->events->on(self::EVENT_DRAIN, $handler);
    }
    
    /**
     * Sets a handler that is called when any of the events occurs: 
     *
     * - there was an error on the stream and it is closed
     * - writable stream was closed after writing all data
     * - there was EOF on a readable stream
     * - cancel() was called
     *
     * @param callable $handler function ($reason) {}
     */
    public function onCompleted(callable $handler)
    {
        if ($this->isClosed()) {
            assert(!!$this->closeReason);
            $handler($this->closeReason);
            return;
        }

        $this->events->on(self::EVENT_COMPLETED, $handler);
    }

    /**
     * Called when all the data were written successfully and 
     * fclose() succeeded, or when there was EOF on read stream
     * and fclose() succeeded
     */
    public function onEnd(callable $handler)
    {
        if ($this->isClosed()) {
            assert(!!$this->closeReason);
            if ($this->closeReason === self::REASON_EOF) {
                $handler();
            }

            return;
        }

        $this->events->on(self::EVENT_END, $handler);
    }

    private function reportCloseOnEof()
    {
        assert(!$this->closeReason);

        $this->closeReason = self::REASON_EOF;
        $this->isReadable = false;
        $this->isWritable = false;

        // $this->events->emit(self::EVENT_DATA_READ, ['', true]);
        $this->events->emit(self::EVENT_COMPLETED, [$this->closeReason]);
        $this->events->emit(self::EVENT_END);
        $this->events->removeAllListeners();
    }
    
    private function closeOnError(\Exception $e)
    {
        assert(!$this->closeReason);

        $this->isReadable = false;
        $this->isWritable = false;
        $this->closeReason = self::REASON_ERROR;
        $this->events->emit(self::EVENT_COMPLETED, [$this->closeReason]);
        $this->events->emit(self::EVENT_ERROR, [$e]);
        $hadErrorListener = $this->events->listeners(self::EVENT_ERROR);

        $this->events->removeAllListeners();

        $this->sourceStream->close();

        // Throw an exception if an error was not handled
        if (!$hadErrorListener) {
            throw $e;
        }
    }
    
    private function reportCloseOnCancelled()
    {
        assert(!$this->closeReason);
        $this->closeReason = self::REASON_CANCELLED;
        $this->isReadable = false;
        $this->isWritable = false;

        $this->events->emit(self::EVENT_COMPLETED, [$this->closeReason]);
        $this->events->removeAllListeners();

        // $this->sourceStream->close();
    }    
}