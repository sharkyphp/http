<?php
/**
 * SharkyPHP Http Library
 *
 * HTTP 中间件接口
 *
 * 只约束 process 的方法签名，不提供任何实现；
 * 继承 Sharky\Contracts\MiddlewareInterface，中间件管道据此把它当作内置中间件
 * （而不是 PSR-15 的 ServerMiddlewareInterface）。
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Sharky\Contracts\MiddlewareInterface as Contract;

interface MiddlewareInterface extends Contract
{
    /**
     * 处理请求
     *
     * 前置逻辑 -> $next($request) -> 后置逻辑；直接返回响应即可短路。
     *
     * @param ServerRequestInterface $request PSR-7 请求实例
     * @param callable $next 下游调用（下一个中间件 / 控制器）
     * @return \Psr\Http\Message\ResponseInterface 响应实例
     */
    public function process(ServerRequestInterface $request, callable $next);
}
