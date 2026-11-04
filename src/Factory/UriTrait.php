<?php
/**
 * SharkyPHP Http Library
 *
 * URI工厂
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Factory;

use Psr\Http\Message\UriInterface;
use Sharky\Http\Message\Uri;

trait UriTrait
{
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
