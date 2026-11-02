<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 路由上下文
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Routing;

use Sharky\Pipeline\CollectTrait;

final class Context
{
    use CollectTrait;

    public string $prefix     = '';
    public string $controller = '';

    // 添加路由前缀（拼接追加）
    public function prefix(string $prefix): self
    {
        $this->prefix .= $prefix;
        return $this;
    }

    // 设置控制器
    public function controller(string $controller): self
    {
        $this->controller = $controller;
        return $this;
    }

    // 获取当前上下文快照
    public function getSnapshot(): self
    {
        return clone $this;
    }
}
