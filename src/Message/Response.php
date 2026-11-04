<?php
/**
 * SharkyPHP Http Library
 *
 * WEB Http响应
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
    protected int $statusCode         = 200;
    protected array $headers          = [];
    protected string $reasonPhrase    = '';
    protected string $protocolVersion = '1.1';
    protected StreamInterface $body;

    protected const STATUS_MAP = [
        100 => "Continue",
        101 => "Switching Protocols",
        200 => "OK",
        201 => "Created",
        202 => "Accepted",
        203 => "Non-Authoritative Information",
        204 => "No Content",
        205 => "Reset Content",
        206 => "Partial Content",
        300 => "Multiple Choices",
        301 => "Moved Permanently",
        302 => "Found",
        303 => "See Other",
        304 => "Not Modified",
        305 => "Use Proxy",
        307 => "Temporary Redirect",
        308 => "Permanent Redirect",
        400 => "Bad Request",
        401 => "Unauthorized",
        402 => "Payment Required",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        406 => "Not Acceptable",
        407 => "Proxy Request Failed",
        408 => "Request Time-out",
        409 => "Conflict",
        410 => "Gone",
        411 => "Length Required",
        412 => "Precondition Failed",
        413 => "Request Entity Too Large",
        414 => "Request-Uri Too Long",
        415 => "Unsupported Media Type",
        416 => "Requested Range Not Satisfiable",
        417 => "Expectation Failed",
        418 => "I'm a teapot",
        500 => "Internal Server Error",
        501 => "Internal Server Error",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Time-out",
        505 => "Version Not Supported",
    ];

    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
    ) {
        $this->statusCode = $statusCode;
        $this->headers    = $headers;
        $this->body       = $body;
    }

    // PSR 实现
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone               = clone $this;
        $clone->statusCode   = $code;
        $clone->reasonPhrase = $reasonPhrase;
        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase ?: self::STATUS_MAP[$this->statusCode] ?? '';
    }

    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $item) {
            $out[$item[0]] = $item[1];
        }
        return $out;
    }

    public function hasHeader(string $name): bool
    {
        $normalized = strtolower($name);
        return isset($this->headers[$normalized]);
    }

    public function getHeader(string $name): array
    {
        $normalized = strtolower($name);
        if (! $this->hasHeader($normalized)) {
            return [];
        }
        return $this->headers[$normalized][1];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        $clone      = clone $this;
        $normalized = strtolower($name);
        if (! is_array($value)) {
            $value = [$value];
        }
        $clone->headers[$normalized] = [$name, $value];
        return $clone;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        $clone      = clone $this;
        $normalized = strtolower($name);
        if (! is_array($value)) {
            $value = [$value];
        }
        if ($clone->hasHeader($normalized)) {
            $clone->headers[$normalized][1] = array_merge($clone->headers[$normalized][1], $value);
        } else {
            $clone->headers[$normalized] = [$name, $value];
        }
        return $clone;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        $clone      = clone $this;
        $normalized = strtolower($name);
        unset($clone->headers[$normalized]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $clone       = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        if (! in_array($version, ['1.0', '1.1', '2.0'], true)) {
            throw new InvalidArgumentException(
                'Invalid HTTP protocol version. Must be one of: 1.0,1.1,2.0'
            );
        }
        $new                  = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    /**
     * 扩展实现：设置 Content-Type
     *
     * $content_type 允许直接传入完整值（如 'application/json; charset=utf-8'），
     * 此时不再追加 charset，避免出现 `; charset=utf-8; charset=utf-8` 这类重复。
     *
     * @param string $content_type 媒体类型，可含参数
     * @param string $charset      未在 $content_type 中声明 charset 时追加的字符集
     */
    public function setContentType($content_type, $charset = ''): static
    {
        $contentType = trim((string) $content_type);
        $charset     = trim((string) $charset);

        if ($charset !== '' && stripos($contentType, 'charset=') === false) {
            $contentType .= "; charset={$charset}";
        }

        $this->setHeader('Content-Type', $contentType);

        return $this;
    }

    // 设置/覆盖 响应头
    public function setHeader(string | array $name, $value = '')
    {
        if (is_array($name)) {
            foreach ($name as $k => $v) {
                $this->setHeader($k, $v);
            }
            return $this;
        } elseif (is_string($name)) {

            $normalized = strtolower($name);
            if (! is_array($value)) {
                $value = [$value];
            }
            $this->headers[$normalized] = [$name, $value];
        }

        return $this;
    }

    // 追加 响应头
    public function addedHeader(string $name, $value): static
    {
        $normalized = strtolower($name);
        if (! is_array($value)) {
            $value = [$value];
        }
        if ($this->hasHeader($normalized)) {
            $this->headers[$normalized][1] = array_merge($this->headers[$normalized][1], $value);
        } else {
            $this->headers[$normalized] = [$name, $value];
        }
        return $this;
    }

    // 设置状态
    public function setStatus($code, string $reasonPhrase = ''): static
    {
        $this->statusCode   = $code;
        $this->reasonPhrase = $reasonPhrase;
        return $this;
    }

    /**
     * 设置响应体
     */
    public function setBody(StreamInterface $body): ResponseInterface
    {
        $this->body = $body;
        return $this;
    }

    // 魔术方法，转换字符串
    public function __toString(): string
    {
        $code    = $this->getStatusCode();
        $reason  = $this->getReasonPhrase();
        $version = $this->getProtocolVersion();

        $lines   = [];
        $lines[] = sprintf('HTTP/%s %d %s', $version, $code, $reason);

        // 全部header
        foreach ($this->getHeaders() as $name => $values) {
            foreach ($values as $val) {
                $lines[] = sprintf('%s: %s', $name, $val);
            }
        }

        // 空行分隔header与body
        $lines[] = '';

        // 读取body内容
        $bodyContent = (string) $this->getBody();
        $lines[]     = $bodyContent;

        return implode("\r\n", $lines);
    }

}
