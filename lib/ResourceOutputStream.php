<?php

namespace Amp\ByteStream;

use Amp\Loop;
use Concurrent\Deferred;
use Concurrent\Task;

/**
 * Output stream abstraction for PHP's stream resources.
 */
final class ResourceOutputStream implements OutputStream
{
    /** @var resource */
    private $resource;

    /** @var string */
    private $watcher;

    /** @var \SplQueue */
    private $writes;

    /** @var bool */
    private $writable = true;

    /** @var int|null */
    private $chunkSize;

    /**
     * @param resource $stream Stream resource.
     * @param int|null $chunkSize Chunk size per `fwrite()` operation.
     */
    public function __construct($stream, int $chunkSize = null)
    {
        if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw new \Error("Expected a valid stream");
        }

        $meta = \stream_get_meta_data($stream);

        if (\strpos($meta["mode"], "r") !== false && \strpos($meta["mode"], "+") === false) {
            throw new \Error("Expected a writable stream");
        }

        \stream_set_blocking($stream, false);
        \stream_set_write_buffer($stream, 0);

        $this->resource = $stream;
        $this->chunkSize = $chunkSize;

        $writes = $this->writes = new \SplQueue;
        $writable = &$this->writable;
        $resource = &$this->resource;

        $this->watcher = Loop::onWritable($stream, static function ($watcher, $stream) use (
            $writes,
            $chunkSize,
            &
            $writable,
            &$resource
        ) {
            try {
                while (!$writes->isEmpty()) {
                    /** @var Deferred $deferred */
                    [$data, $previous, $deferred] = $writes->shift();
                    $length = \strlen($data);

                    if ($length === 0) {
                        $deferred->resolve(0);
                        continue;
                    }

                    // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                    // Use conditional, because PHP doesn't like getting null passed
                    if ($chunkSize) {
                        $written = @\fwrite($stream, $data, $chunkSize);
                    } else {
                        $written = @\fwrite($stream, $data);
                    }

                    \assert($written !== false, "Trying to write on a previously fclose()'d resource. Do NOT manually fclose() resources the loop still has a reference to.");
                    if ($written === 0) {
                        // fwrite will also return 0 if the buffer is already full.
                        if (\is_resource($stream) && !@\feof($stream)) {
                            $writes->unshift([$data, $previous, $deferred]);
                            return;
                        }

                        $resource = null;
                        $writable = false;

                        $message = "Failed to write to socket";
                        if ($error = \error_get_last()) {
                            $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                        }
                        $exception = new StreamException($message);
                        $deferred->fail($exception);
                        while (!$writes->isEmpty()) {
                            [, , $deferred] = $writes->shift();
                            $deferred->fail($exception);
                        }

                        Loop::cancel($watcher);
                        return;
                    }

                    if ($length > $written) {
                        $data = \substr($data, $written);
                        $writes->unshift([$data, $written + $previous, $deferred]);
                        return;
                    }

                    $deferred->resolve($written + $previous);
                }
            } finally {
                if ($writes->isEmpty()) {
                    Loop::disable($watcher);
                }
            }
        });

        Loop::disable($this->watcher);
    }

    /**
     * Writes data to the stream.
     *
     * @param string $data Bytes to write.
     *
     * @return void
     *
     * @throws ClosedException If the stream has already been closed.
     */
    public function write(string $data): void
    {
        $this->send($data);
    }

    /**
     * Closes the stream after all pending writes have been completed. Optionally writes a final data chunk before.
     *
     * @param string $finalData Bytes to write.
     *
     * @return void
     *
     * @throws ClosedException If the stream has already been closed.
     */
    public function end(string $finalData = ""): void
    {
        $this->send($finalData, true);
    }

    /**
     * @param string $data Data to write.
     * @param bool   $end Whether to close the stream.
     *
     * @throws ClosedException
     */
    private function send(string $data, bool $end = false): void
    {
        if (!$this->writable) {
            throw new ClosedException("The stream is not writable");
        }

        $length = \strlen($data);
        $written = 0;

        if ($end) {
            $this->writable = false;
        }

        if ($this->writes->isEmpty()) {
            if ($length === 0) {
                if ($end) {
                    $this->close();
                }
                return;
            }

            // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
            // Use conditional, because PHP doesn't like getting null passed.
            if ($this->chunkSize) {
                $written = @\fwrite($this->resource, $data, $this->chunkSize);
            } else {
                $written = @\fwrite($this->resource, $data);
            }

            \assert($written !== false, "Trying to write on a previously fclose()'d resource. Do NOT manually fclose() resources the loop still has a reference to.");

            if ($length === $written) {
                if ($end) {
                    $this->close();
                }
                return;
            }

            $data = \substr($data, $written);
        }

        $deferred = new Deferred;
        $this->writes->push([$data, $written, $deferred]);
        Loop::enable($this->watcher);
        $awaitable = $deferred->awaitable();

        if ($end) {
            Deferred::combine([$awaitable], function (Deferred $deferred) {
                $deferred->resolve(); // dummy resolve
                $this->close();
            });
        }

        Task::await($awaitable);
    }

    /**
     * Closes the stream forcefully. Multiple `close()` calls are ignored.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->resource) {
            // Error suppression, as resource might already be closed
            $meta = @\stream_get_meta_data($this->resource);

            if ($meta && \strpos($meta["mode"], "+") !== false) {
                @\stream_socket_shutdown($this->resource, \STREAM_SHUT_WR);
            } else {
                @\fclose($this->resource);
            }
        }

        $this->free();
    }

    /**
     * Nulls reference to resource, marks stream non-writable, and fails any pending write.
     */
    private function free(): void
    {
        $this->resource = null;
        $this->writable = false;

        if (!$this->writes->isEmpty()) {
            $exception = new ClosedException("The socket was closed before writing completed");
            do {
                /** @var Deferred $deferred */
                [, , $deferred] = $this->writes->shift();
                $deferred->fail($exception);
            } while (!$this->writes->isEmpty());
        }

        Loop::cancel($this->watcher);
    }

    /**
     * @return resource|null Stream resource or null if end() has been called or the stream closed.
     */
    public function getResource()
    {
        return $this->resource;
    }

    public function __destruct()
    {
        if ($this->resource !== null) {
            $this->free();
        }
    }
}
