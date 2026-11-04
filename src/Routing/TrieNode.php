<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 路由前缀树
 *
 * 节点为「static / param / candidates」三槽结构。缓存装载后整棵树以
 * 紧凑索引数组（0/1/2 槽位）存在，因此 ArrayAccess 同时接受槽位常量
 * 与属性名，匹配阶段节点树与缓存数组可共用同一套取值代码。
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

class TrieNode implements ArrayAccess, ArrayableInterface
{
    // 紧凑数组槽位（与属性顺序一致，缓存文件里就是这三个下标）
    public const STATIC     = 0;
    public const PARAM      = 1;
    public const CANDIDATES = 2;

    public array $static     = [];
    public ?TrieNode $param  = null;
    public array $candidates = [];

    // 槽位 => 属性名
    private const FIELDS = [
        self::STATIC     => 'static',
        self::PARAM      => 'param',
        self::CANDIDATES => 'candidates',
    ];

    // ---------------- ArrayAccess 实现 ----------------
    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $this->field($offset) ?? '');
    }

    public function offsetGet(mixed $offset): mixed
    {
        $field = $this->field($offset);

        if ($field === null) {
            throw new InvalidArgumentException('Undefined TrieNode property key: ' . (is_scalar($offset) ? (string) $offset : get_debug_type($offset)));
        }

        return $this->$field;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RuntimeException('TrieNode readonly on match stage. Build by property.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new RuntimeException('TrieNode readonly.');
    }

    // 槽位常量或属性名 => 属性名
    private function field(mixed $offset): ?string
    {
        $field = is_int($offset) ? (self::FIELDS[$offset] ?? null) : $offset;

        return is_string($field) && property_exists($this, $field) ? $field : null;
    }

    // ---------------- 导出：紧凑索引数组（仅编译命令调用） ----------------
    public function toArray(): array
    {
        $data = [
            self::STATIC     => [],
            self::PARAM      => null,
            self::CANDIDATES => [],
        ];

        // 递归导出全部静态子节点
        foreach ($this->static as $segment => $childNode) {
            $data[self::STATIC][$segment] = $childNode->toArray();
        }

        // 递归导出动态参数子节点
        if ($this->param !== null) {
            $data[self::PARAM] = $this->param->toArray();
        }

        // 遍历节点的候选路由（可能是 Route 实例，也可能是缓存装载的数组）
        foreach ($this->candidates as $key => $route) {
            $data[self::CANDIDATES][$key] = $route instanceof Route ? $route->toArray() : $route;
        }

        return $data;
    }

    // ---------------- 还原：紧凑索引数组 => 节点树（缓存装载后再次注册路由时使用） ----------------
    public static function fromArray(array $data): self
    {
        $node = new self();

        foreach ((array) ($data[self::STATIC] ?? []) as $segment => $child) {
            $node->static[(string) $segment] = self::fromArray((array) $child);
        }

        $node->param      = is_array($data[self::PARAM] ?? null) ? self::fromArray($data[self::PARAM]) : null;
        $node->candidates = (array) ($data[self::CANDIDATES] ?? []);

        return $node;
    }

    // ---------------- 遍历：产出树中所有候选路由（节点树 / 紧凑数组均可） ----------------
    public static function flatten(array | self $node): iterable
    {
        foreach ((array) ($node[self::CANDIDATES] ?? []) as $route) {
            yield $route;
        }

        foreach ((array) ($node[self::STATIC] ?? []) as $child) {
            yield from self::flatten($child);
        }

        if (is_array($node[self::PARAM] ?? null) || $node[self::PARAM] instanceof self) {
            yield from self::flatten($node[self::PARAM]);
        }
    }
}
