<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 客户端请求 实现 PSR-7 RequestInterface
 *
 * @package Sharky\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use Psr\Http\Message\RequestInterface;

final class Request extends AbstractRequest
{
    protected string $requestTarget = '';

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== '') {
            return $this->requestTarget;
        }
        $path   = $this->uri->getPath();
        $query  = $this->uri->getQuery();
        $target = $path . ($query ? '?' . $query : '');
        return $target === '' ? '/' : $target;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $new                = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }
}
