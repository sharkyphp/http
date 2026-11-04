<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 服务端请求 实现 PSR-7 ServerRequestInterface
 *
 * @package Sharky\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Sharky\Http\Message\AbstractRequest;
use Sharky\Http\Message\Stream;
use Sharky\Http\Message\Uri;

final class ServerRequest extends AbstractRequest implements ServerRequestInterface
{
    protected array $get;
    protected array $post;
    protected array $server;
    protected array $cookie;
    protected array $files;
    protected array $headers;
    protected string $rawBody;
    protected array $attributes = [];
    // getParsedBody() 的惰性解析结果与解析标记（withParsedBody() 可直接预置）
    protected bool $parsed      = false;
    protected mixed $parsedBody = null;

    public function __construct(
        string $method,
        Uri | string $uri,
        array $server,
        array $get,
        array $post,
        array $cookie = [],
        array $files = [],
        string $rawBody = ''
    ) {
        $uri = is_string($uri) ? new Uri($uri) : $uri;

        $this->method  = strtoupper($method);
        $this->uri     = $uri;
        $this->get     = $get;
        $this->post    = $post;
        $this->server  = $server;
        $this->cookie  = $cookie;
        $this->files   = $files;
        $this->rawBody = $rawBody;

        $this->mapHeaders();
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $scheme;
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $uri    = new Uri("{$scheme}://{$host}{$uri}");
        return new self(
            $method,
            $uri,
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            file_get_contents('php://input') ?: ''
        );
    }

    public static function create(
        string $method,
        string $uri,
        array $server = [],
        array $get = [],
        array $post = [],
        array $cookie = [],
        array $files = []
    ): self {

        $server = array_merge($server, [
            'REQUEST_METHOD' => $method,
        ]);
        return  new self($method, $uri, $server, $get, $post, $cookie, $files, '');
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $this->post[$key] ?? $default;
    }

    public function getCookie(string $key, mixed $default = null): mixed
    {
        return $this->cookie[$key] ?? $default;
    }

    public function getProxys(): array
    {
        $val = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
        return $val ? array_map('trim', explode(',', $val)) : [];
    }

    public function getClientIP(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '';
    }

    public function isAjax(): bool
    {
        return strtolower($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    private function mapHeaders(): void
    {
        $headers = [];
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = [$this->server['CONTENT_TYPE']];
        }
        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = [$this->server['CONTENT_LENGTH']];
        }
        $this->headers = array_reduce(array_keys($this->server), function ($carry, $key) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerKey         = str_replace('_', '-', substr($key, 5));
                $headerKey         = ucwords(strtolower($headerKey), '-');
                $carry[$headerKey] = [$this->server[$key]];
            }
            return $carry;
        }, $headers);
    }

    // ========== 重写 RequestInterface 的两个方法，服务端强制绑定Uri ==========
    public function getRequestTarget(): string
    {
        $uri    = $this->getUri();
        $path   = $uri->getPath();
        $query  = $uri->getQuery();
        $target = $path . ($query ? '?' . $query : '');
        return $target === '' ? '/' : $target;
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        $new    = clone $this;
        $oldUri = $new->getUri();
        $parsed = parse_url($requestTarget);
        $newUri = $oldUri
            ->withPath($parsed['path'] ?? '/')
            ->withQuery($parsed['query'] ?? '');
        $new->uri = $newUri;
        // 同步父类的uri
        $new->uri = $newUri;
        return $new;
    }

    // ========== 重写 getUri，服务端懒加载，从server构建Uri ==========
    public function getUri(): UriInterface
    {
        if (! $this->uri) {

            $scheme = ($this->server['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
            $scheme = $this->server['HTTP_X_FORWARDED_PROTO'] ?? $scheme;

            $host = $this->server['HTTP_HOST'] ?? '';
            $uri  = $this->server['REQUEST_URI'] ?? '';

            $this->uri = new Uri("{$scheme}://{$host}{$uri}");
        }
        return $this->uri;
    }

    // ========== ServerRequestInterface 全部方法 ==========
    public function getServerParams(): array
    {
        return $this->server;
    }

    public function getCookieParams(): array
    {
        return $this->cookie;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new         = clone $this;
        $new->cookie = $cookies;
        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->get;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $new      = clone $this;
        $new->get = $query;
        return $new;
    }

    public function getParsedBody()
    {
        if (! $this->parsed) {
            $this->parsed = true;
            $method       = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
            $ct           = strtolower(trim($this->server['CONTENT_TYPE'] ?? ''));
            if ($method === 'POST' && (str_starts_with($ct, 'application/x-www-form-urlencoded') || str_starts_with($ct, 'multipart/form-data'))) {
                $this->parsedBody = $this->post;
            } elseif (str_starts_with($ct, 'application/json')) {
                $data             = json_decode($this->rawBody, true);
                $this->parsedBody = json_last_error() === JSON_ERROR_NONE ? $data : null;
            } else {
                $this->parsedBody = null;
            }
        }
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $new             = clone $this;
        $new->parsedBody = $data;
        $new->parsed     = true;
        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->files;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $new        = clone $this;
        $new->files = $uploadedFiles;
        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $new                    = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        $new = clone $this;

        return $new->setUri($uri, $preserveHost);
    }

    private function setUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        $this->uri = $uri;
        if ($preserveHost === false) {
            $host   = $uri->getHost();
            $port   = $uri->getPort();
            $scheme = $uri->getScheme();

            // 添加非默认端口号
            if ($scheme === "https" && $port != 443) {
                $host = "{$host}:{$port}";
            } else if ($scheme === "http" && $port != 80) {
                $host = "{$host}:{$port}";
            }

            $this->server['HTTP_HOST'] = $host;

            if ($scheme === "https") {
                $this->server['HTTPS'] = "on";
            }
        }

        $path  = $uri->getPath();
        $query = $uri->getQuery();

        $this->server['REQUEST_URI'] = "{$path}?{$query}";

        return $this;
    }

    public function getBody(): StreamInterface
    {
        if (! $this->body) {
            $this->body = Stream::fromString($this->rawBody);
        }
        return $this->body;
    }

    public function withBody(StreamInterface $body): ServerRequestInterface
    {
        $new          = clone $this;
        $new->body    = $body;
        $new->rawBody = (string) $body;
        return $new;
    }
}
