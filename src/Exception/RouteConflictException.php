<?php
/**
 * SharkyPHP Http Library
 *
 * 路由冲突异常（同 path + 同 method 重复注册）
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 */
declare (strict_types = 1);

namespace Sharky\Http\Exception;

use Throwable;

class RouteConflictException extends HttpException
{
    // 路由冲突属于配置错误，按 500 处理
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(500, $message, $code, $previous);
        $this->statusCode = 500;
    }

    // 构造冲突信息：[METHOD] path 已被 X 注册，无法再注册给 Y
    public static function make(string $path, string $method, string $exists, string $incoming): self
    {
        return new self(sprintf(
            'Route conflict: [%s] %s already registered to [%s], cannot register to [%s].',
            strtoupper($method),
            $path,
            $exists,
            $incoming
        ));
    }
}
