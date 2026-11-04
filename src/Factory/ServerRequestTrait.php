<?php
/**
 * SharkyPHP Http Library
 *
 * 服务端请求工厂
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Factory;

use Psr\Http\Message\ServerRequestInterface;
use Sharky\Http\Message\ServerRequest;

trait ServerRequestTrait
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        if (is_string($uri) && method_exists(self::class, 'createUri')) {
            $uri = $this->createUri($uri);
        }
        return new ServerRequest($method, $uri, $serverParams);
    }

    public function serverRequestCapture()
    {
        return ServerRequest::capture();
    }
}
