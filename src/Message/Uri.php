<?php
/**
 * SharkyPHP Http Library
 *
 * URI 实现 PSR-7 UriInterface
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    private string $scheme   = '';
    private string $userInfo = '';
    private string $host     = '';
    private ?int $port       = null;
    private string $path     = '';
    private string $query    = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException(sprintf('Invalid URI: "%s"', $uri));
        }

        $this->scheme   = strtolower($parts['scheme'] ?? '');
        $this->userInfo = $parts['user'] ?? '';
        if (isset($parts['pass'])) {
            $this->userInfo .= ':' . $parts['pass'];
        }
        $this->host     = strtolower($parts['host'] ?? '');
        $this->port     = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path     = $parts['path'] ?? '';
        $this->query    = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $defaultPort = $this->getDefaultPort();
            if ($this->port !== $defaultPort) {
                $authority .= ':' . $this->port;
            }
        }
        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        $default = $this->getDefaultPort();
        return $this->port !== $default ? $this->port : null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $new         = clone $this;
        $new->scheme = strtolower($scheme);
        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $new           = clone $this;
        $new->userInfo = $password !== null ? "{$user}:{$password}" : $user;
        return $new;
    }

    public function withHost(string $host): UriInterface
    {
        $new       = clone $this;
        $new->host = strtolower($host);
        return $new;
    }

    public function withPort(?int $port): UriInterface
    {
        $new       = clone $this;
        $new->port = $port;
        return $new;
    }

    public function withPath(string $path): UriInterface
    {
        $new       = clone $this;
        $new->path = $path;
        return $new;
    }

    public function withQuery(string $query): UriInterface
    {
        $new        = clone $this;
        $new->query = $query;
        return $new;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $new           = clone $this;
        $new->fragment = $fragment;
        return $new;
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }
        $uri .= $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }
        return $uri;
    }

    private function getDefaultPort(): ?int
    {
        return match ($this->scheme) {
            'http'  => 80,
            'https' => 443,
            default => null
        };
    }
}
