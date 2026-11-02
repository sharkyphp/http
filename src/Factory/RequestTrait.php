<?php
/**
 * SharkyPHP Http Library
 *
 * 请求工厂
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Factory;

use Psr\Http\Message\RequestInterface;
use Sharky\Http\Message\Request;

trait RequestTrait
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        if (is_string($uri) && method_exists(self::class, 'createUri')) {
            $uri = $this->createUri($uri);
        }
        return new Request($method, $uri);
    }
}
