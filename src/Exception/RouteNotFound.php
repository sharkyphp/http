<?php
/**
 * SharkyPHP Http Library
 *
 * Http路由不存在
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Http\Exception;

class RouteNotFound extends HttpException
{
    public function __construct(int $statusCode = 404, string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($statusCode, $message, $code, $previous);
        $this->statusCode = $statusCode;
    }
}
