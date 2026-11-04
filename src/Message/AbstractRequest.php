<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 抽象请求 实现 PSR-7 RequestInterface
 *
 * @package Sharky\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;

abstract class AbstractRequest implements RequestInterface
{
    protected string $method;
    protected ?UriInterface $uri = null;
    protected array $headers = [];
    protected ?StreamInterface $body = null;
    protected string $protocolVersion = '1.1';

    public function __construct(string $method, UriInterface $uri)
    {
        $this->method = strtoupper($method);
        $this->uri = $uri;
    }

    // ========== RequestInterface 通用实现 ==========
    abstract public function getRequestTarget(): string;
    abstract public function withRequestTarget(string $requestTarget): RequestInterface;

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost) {
            $host = $uri->getHost();
            if ($uri->getPort()) {
                $host .= ':' . $uri->getPort();
            }
            $new->headers['Host'] = [$host];
        }
        return $new;
    }

    // ========== MessageInterface ==========
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        $name = ucwords(strtolower($name), '-');
        return isset($this->headers[$name]);
    }

    public function getHeader(string $name): array
    {
        $name = ucwords(strtolower($name), '-');
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $new = clone $this;
        $key = ucwords(strtolower($name), '-');
        $new->headers[$key] = is_array($value) ? $value : [$value];
        return $new;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $new = clone $this;
        $key = ucwords(strtolower($name), '-');
        $val = is_array($value) ? $value : [$value];
        $old = $new->headers[$key] ?? [];
        $new->headers[$key] = array_merge($old, $val);
        return $new;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $new = clone $this;
        $key = ucwords(strtolower($name), '-');
        unset($new->headers[$key]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        if (!$this->body) {
            $this->body = Stream::fromString('');
        }
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }
}
