<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 路由条目
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Routing;

use ArrayAccess;
use InvalidArgumentException;
use RuntimeException;
use Sharky\Contracts\ArrayableInterface;
use Sharky\Pipeline\CollectTrait;

final class Route implements ArrayAccess, ArrayableInterface
{
    use CollectTrait;

    private array $methods;
    private string $path;
    private string $pattern;
    private mixed $finalHandler;
    private array $paramNames;
    private array $paramRules;

    // 未声明方法时的默认方法（ANY 视为通配）
    public const DEFAULT_METHODS = ['ANY'];

    public function __construct(string | array $methods, string $path, mixed $finalHandler, array $middlewares = [])
    {
        $this->methods      = $this->normalizeMethods(is_string($methods) ? [$methods] : $methods);
        $this->path         = $path;
        $this->finalHandler = $finalHandler;
        $this->middlewares  = $middlewares;

        // 编译正则表达式
        $this->compileRegex();
    }

    // 规范化方法列表：统一大写去重，空列表按 ANY 处理
    private function normalizeMethods(array $methods): array
    {
        $methods = array_map(static fn($method) => strtoupper((string) $method), $methods);
        $methods = array_values(array_filter($methods, static fn($method) => $method !== ''));

        return empty($methods) ? self::DEFAULT_METHODS : array_values(array_unique($methods));
    }

    // 添加匹配组规则
    public function where(array $rule): self
    {
        $this->paramRules = array_merge($this->paramRules, $rule);
        return $this;
    }

    private function compileRegex(): void
    {
        $this->paramNames = [];
        $this->paramRules = [];

        // 兼容两种占位符写法：(name:rule) 与 {name:rule} / {name}
        $normalized = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]*))?\}#',
            static fn($m) => '(' . $m[1] . (isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '') . ')',
            $this->path
        );

        $pattern = '#(?<!\\\\)\(([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:\\\\.|.)*?))?\)#';
        $final   = preg_replace_callback($pattern, function ($m) {
            $this->paramNames[]      = $m[1];
            $this->paramRules[$m[1]] = str_replace(['\\(', '\\)'], ['(', ')'], $m[2] ?? '.+');;
            return '([^/]+)';
        }, $normalized);

        $this->pattern = "#^{$final}$#";
    }

    // ---------------- ArrayAccess 实现 ----------------
    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! property_exists($this, $offset)) {
            throw new InvalidArgumentException("Undefined Route property key: {$offset}");
        }
        return $this->$offset;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RuntimeException('Route readonly on match stage. Build by property.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new RuntimeException('Route readonly.');
    }

    // ---------------- toArray 递归导出（仅编译命令调用） ----------------
    public function toArray(): array
    {
        return [
            'path'         => $this->path,
            'pattern'      => $this->pattern,
            'methods'      => $this->methods,
            'finalHandler' => $this->finalHandler,
            'middlewares'  => $this->middlewares,
            'paramNames'   => $this->paramNames,
            'paramRules'   => $this->paramRules,
        ];
    }
}
