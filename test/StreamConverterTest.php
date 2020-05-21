<?php

namespace Amp\ByteStream\Test;

use Amp\ByteStream\StreamConverter;
use Amp\ByteStream\StreamException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\StreamSource;

class StreamConverterTest extends AsyncTestCase
{
    public function testReadIterator()
    {
        $values = ["abc", "def", "ghi"];

        $source = new StreamSource;
        $stream = new StreamConverter($source->stream());

        foreach ($values as $value) {
            $source->yield($value);
        }

        $source->complete();

        $buffer = "";
        while (($chunk = yield $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        $this->assertSame(\implode($values), $buffer);
        $this->assertNull(yield $stream->read());
    }

    public function testFailingIterator()
    {
        $exception = new TestException;
        $value = "abc";

        $source = new StreamSource;
        $stream = new StreamConverter($source->stream());

        $source->yield($value);
        $source->fail($exception);

        $callable = $this->createCallback(1);

        try {
            while (($chunk = yield $stream->read()) !== null) {
                $this->assertSame($value, $chunk);
            }

            $this->fail("No exception has been thrown");
        } catch (TestException $reason) {
            $this->assertSame($exception, $reason);
            $callable(); // <-- ensure this point is reached
        }
    }

    public function testThrowsOnNonStringIteration()
    {
        $this->expectException(StreamException::class);

        $value = 42;

        $source = new StreamSource;
        $stream = new StreamConverter($source->stream());

        $source->yield($value);

        yield $stream->read();
    }

    public function testFailsAfterException()
    {
        $this->expectException(StreamException::class);

        $value = 42;

        $source = new StreamSource;
        $stream = new StreamConverter($source->stream());

        $source->yield($value);

        try {
            yield $stream->read();
        } catch (StreamException $e) {
            yield $stream->read();
        }
    }
}
