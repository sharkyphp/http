<?php
/**
 * SharkyPHP Http Library
 *
 * 流 实现 PSR-7 StreamInterface
 *
 * @package Sharky\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;
    private ?string $buffer = null;

    public function __construct($resource)
    {
        $this->resource = $resource;
    }

    public static function fromString(string $content): self
    {
        $res = fopen('php://temp', 'rwb+');
        fwrite($res, $content);
        rewind($res);
        return new self($res);
    }

    public static function fromResource($resource): self
    {
        if (! is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException(sprintf('Must be valid stream resource'));
        }
        return new self($resource);
    }

    public function __toString(): string
    {
        if (! $this->resource) {
            return '';
        }
        rewind($this->resource);
        return stream_get_contents($this->resource);
    }

    public function close(): void
    {
        if ($this->resource) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $ret            = $this->resource;
        $this->resource = null;
        return $ret;
    }

    public function getSize(): ?int
    {
        if (! $this->resource) {
            return null;
        }

        $stat = fstat($this->resource);
        return $stat['size'] ?? null;
    }

    public function tell(): int
    {
        if (! $this->resource) {
            throw new RuntimeException(sprintf('Stream detached'));
        }
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new RuntimeException(sprintf('ftell failed'));
        }
        return $pos;
    }

    public function eof(): bool
    {
        return ! $this->resource || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->resource && stream_get_meta_data($this->resource)['seekable'];
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (! $this->resource) {
            throw new RuntimeException(sprintf('Stream detached'));
        }
        if (! $this->isSeekable()) {
            throw new RuntimeException(sprintf('Stream not seekable'));
        }
        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException(sprintf('Seek failed'));
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        if (! $this->resource) {
            return false;
        }

        $meta = stream_get_meta_data($this->resource);
        $mode = $meta['mode'];
        return strpbrk($mode, 'wax+') !== false;
    }

    public function write(string $string): int
    {
        if (! $this->resource || ! $this->isWritable()) {
            throw new RuntimeException(sprintf('Stream not writable'));
        }
        $wrote = fwrite($this->resource, $string);
        if ($wrote === false) {
            throw new RuntimeException(sprintf('Write failed'));
        }
        return $wrote;
    }

    public function isReadable(): bool
    {
        if (! $this->resource) {
            return false;
        }

        $meta = stream_get_meta_data($this->resource);
        $mode = $meta['mode'];
        return strpbrk($mode, 'r+') !== false;
    }

    public function read(int $length): string
    {
        if (! $this->resource || ! $this->isReadable()) {
            throw new RuntimeException(sprintf('Stream not readable'));
        }
        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new RuntimeException(sprintf('Read failed'));
        }
        return $data;
    }

    public function getContents(): string
    {
        if (! $this->resource || ! $this->isReadable()) {
            throw new RuntimeException(sprintf('Stream not readable'));
        }
        $data = stream_get_contents($this->resource);
        if ($data === false) {
            throw new RuntimeException(sprintf('Read failed'));
        }
        return $data;
    }

    public function getMetadata(?string $key = null)
    {
        if (! $this->resource) {
            return $key ? null : [];
        }
        $meta = stream_get_meta_data($this->resource);
        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }
}
