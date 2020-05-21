<?php

namespace Amp\ByteStream;

use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;
use Amp\Stream;

final class StreamConverter implements InputStream
{
    /** @var Stream<string> */
    private $stream;
    /** @var \Throwable|null */
    private $exception;
    /** @var bool */
    private $pending = false;

    /**
     * @psalm-param Stream<string> $iterator
     */
    public function __construct(Stream $stream)
    {
        $this->stream = $stream;
    }

    /** @inheritdoc */
    public function read(): Promise
    {
        if ($this->exception) {
            return new Failure($this->exception);
        }

        if ($this->pending) {
            throw new PendingReadError;
        }

        $this->pending = true;
        /** @var Deferred<string|null> $deferred */
        $deferred = new Deferred;

        $this->stream->continue()->onResolve(function ($error, $chunk) use ($deferred) {
            $this->pending = false;

            if ($error) {
                $this->exception = $error;
                $deferred->fail($error);
            } elseif ($chunk !== null) {
                if (!\is_string($chunk)) {
                    $this->exception = new StreamException(\sprintf(
                        "Unexpected iterator value of type '%s', expected string",
                        \is_object($chunk) ? \get_class($chunk) : \gettype($chunk)
                    ));

                    $deferred->fail($this->exception);

                    return;
                }

                $deferred->resolve($chunk);
            } else {
                $deferred->resolve();
            }
        });

        return $deferred->promise();
    }
}
