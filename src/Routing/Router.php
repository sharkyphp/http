<?php
/**
 * SharkyPHP Http Library
 *
 * WEB 路由管理
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

use Psr\Http\Message\ServerRequestInterface;
use Sharky\Http\Exception\MethodNotAllowed;
use Sharky\Http\Exception\RouteConflictException;
use Sharky\Http\Exception\RouteNotFound;
use Sharky\Support\Helper;

final class Router
{
    /**
     * 路由表
     *
     * 声明注册阶段存 Route 实例（可继续 where()/middleware() 调整）；
     * 缓存装载阶段为 null，需要时再从前缀树展开成数组，匹配本身用不到。
     *
     * @var array<int, Route|array<string, mixed>>|null
     */
    private ?array $routes = null;

    /**
     * 前缀树
     *
     * 开发态是 TrieNode 节点树；缓存装载后直接是嵌套数组（匹配只读，
     * 靠 ArrayAccess 取 static/param/candidates，无需还原节点对象）。
     */
    private array | TrieNode $root;
    private Context $context;
    private Context $baseSnapshot;
    private mixed $fallback = null;

    // 路由占位检测表：[path => [method => 来源描述]]
    private array $occupied = [];

    private const ANY_METHOD = 'ANY';

    // 缓存条目的结构兜底（缓存由本框架生成，此处只补缺失键，不做重编译）
    private const ROUTE_DEFAULTS = [
        'path'         => '',
        'pattern'      => '',
        'methods'      => Route::DEFAULT_METHODS,
        'finalHandler' => null,
        'middlewares'  => [],
        'paramNames'   => [],
        'paramRules'   => [],
    ];

    // 路径参数占位：(name) 或 (name:regex)
    private const PARAM_PATTERN = '#(?<!\\\\)\(([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:\\\\.|.)*?))?\)#';

    private const ALL_HTTP_METHODS = [
        'GET',
        'PUT',
        'PATCH',
        'DELETE',
        'COPY',
        'HEAD',
        'OPTIONS',
        'LINK',
        'UNLINK',
        'PURGE',
        'LOCK',
        'UNLOCK',
        'PROPFIND',
        'VIEW',
    ];

    public function __construct()
    {
        // 初始化前缀树
        $this->root = new TrieNode();
        // 初始化空上下文
        $this->context = new Context();
        // 克隆一份作为初始基线快照
        $this->baseSnapshot = $this->context->getSnapshot();
    }

    // 清空路由表（前缀树 / 占位表 / 上下文 / 兜底）
    public function flush(): self
    {
        $this->routes       = null;
        $this->occupied     = [];
        $this->root         = new TrieNode();
        $this->context      = new Context();
        $this->baseSnapshot = $this->context->getSnapshot();
        $this->fallback     = null;

        return $this;
    }

    /**
     * 加载路由
     *
     * 加载规则：
     *  - 生产环境（APP_ENV=production）：优先加载缓存文件，命中后
     *    不再加载路由声明文件、也不扫描路由注解；
     *  - 其他环境：忽略缓存，加载路由声明文件并扫描路由/中间件注解，
     *    两者合并注册，同 path + 同 method 视为冲突并抛异常。
     *
     * 注意：路由声明文件内部通过 Route 门面注册，写入的是容器中的共享实例，
     * 因此请经由 `Route::loadRoutes()` 调用（或直接使用容器中的同一实例）。
     *
     * @param string $path    路由声明文件目录
     * @param array  $scan    注解扫描目录 [命名空间 => 目录]，留空取 http.routing.scan 配置
     * @param bool   $refresh true = 忽略缓存强制重建（脚手架编译命令使用）
     */
    public function loadRoutes(string $path, array $scan = [], bool $refresh = false): self
    {
        $cacheFile = RouteCache::file();

        // 生产环境：优先加载缓存
        if (! $refresh && Helper::isProduction() && RouteCache::exists($cacheFile)) {
            return $this->loadCache($cacheFile);
        }

        // 其他环境：忽略缓存，加载路由声明文件
        $this->loadDeclaration($path);

        // 扫描路由/中间件注解，与声明文件合并（冲突抛异常）
        $this->registerRoutes(
            $this->scanner()->scan($scan ?: RouteCache::defaultScan())
        );

        return $this;
    }

    // 加载路由声明文件
    private function loadDeclaration(string $path): void
    {
        $files = glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [];

        // 保证加载顺序稳定
        sort($files);

        // 使用 require（而非 require_once）：编译命令可 flush() 后重复加载
        foreach ($files as $file) {
            require $file;
        }
    }

    /**
     * 批量注册路由声明（由注解扫描产出，含冲突检测）
     *
     * @param array<int, array<string, mixed>> $definitions
     */
    public function registerRoutes(array $definitions): self
    {
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {continue;}

            $methods = $definition['methods'] ?? [];
            $path    = (string) ($definition['path'] ?? '');
            $handler = $definition['handler'] ?? null;

            if ($path === '' || $handler === null) {continue;}

            $route = $this->addRoute(is_array($methods) ? $methods : (string) $methods, $path, $handler);

            $middleware = (array) ($definition['middleware'] ?? []);
            if (! empty($middleware)) {
                $route->middleware(array_values($middleware));
            }
        }

        return $this;
    }

    // 获取注解扫描器
    public function scanner(): RouteScanner
    {
        return new RouteScanner();
    }

    /**
     * 加载路由缓存
     *
     * 缓存里存的已是编译好的整棵前缀树（嵌套数组），装载就是一次赋值：
     * 不还原 Route 实例、不重建节点树、不重编译正则、不做冲突检测。
     * 匹配阶段只读且全程走 ArrayAccess，数组形态与节点树等价。
     *
     * 旧版缓存（扁平路由列表）仍兼容，逐条插入重建前缀树。
     */
    public function loadCache(?string $file = null): self
    {
        $payload = RouteCache::load($file);

        // 新版：整棵前缀树（紧凑索引数组）
        if (isset($payload['trie']) && is_array($payload['trie'])) {
            $this->root = $payload['trie'] + [
                TrieNode::STATIC     => [],
                TrieNode::PARAM      => null,
                TrieNode::CANDIDATES => [],
            ];
            $this->routes = null;
            return $this;
        }

        // 旧版：路由列表
        foreach ($payload as $data) {
            if (! is_array($data)) {continue;}
            $this->restoreRoute($data);
        }

        return $this;
    }

    // 装载一条已编译的路由（不入冲突表）
    public function restoreRoute(array | Route $route): self
    {
        $route = $route instanceof Route ? $route->toArray() : $route + self::ROUTE_DEFAULTS;

        // 缓存若把方法存成标量，兜底包成数组
        if (! is_array($route['methods'])) {
            $route['methods'] = [(string) $route['methods']];
        }

        $this->routes ??= $this->collectRoutes();
        $this->routes[] = $route;
        $this->insert($route);

        return $this;
    }

    /**
     * 导出路由缓存文件
     *
     * @return string 缓存文件绝对路径
     */
    public function export(?string $file = null): string
    {
        return RouteCache::dump(['trie' => $this->trie()], $file);
    }

    /**
     * 导出可缓存的路由数组
     *
     * @return array<int, array<string, mixed>>
     */
    public function dumpRoutes(): array
    {
        $routes = [];
        foreach ($this->getRoutes() as $route) {
            $routes[] = $route instanceof Route ? $route->toArray() : $route;
        }

        return $routes;
    }

    /**
     * 获取全部路由条目（声明注册为 Route 实例，缓存装载为数组）
     *
     * 缓存装载后路由表是空的，这里按需从前缀树展开一次并缓存。
     *
     * @return array<int, Route|array<string, mixed>>
     */
    public function getRoutes(): array
    {
        return $this->routes ??= $this->collectRoutes();
    }

    // 按需从当前前缀树展开路由列表（节点树与缓存数组同样适用）
    private function collectRoutes(): array
    {
        // flatten 内部用 yield from 递归，键会重复，这里不保留键
        return iterator_to_array(TrieNode::flatten($this->root), false);
    }

    // 前缀树的数组形态（用于导出缓存）
    private function trie(): array
    {
        return is_array($this->root) ? $this->root : $this->root->toArray();
    }

    /**
     * 注册「未匹配到任何路由」时的兜底处理器（404 页）。
     * @param mixed $handler 兜底处理器，可以是闭包、控制器方法字符串或其他可调用类型
     */
    public function fallback(mixed $handler): self
    {
        $this->fallback = $this->resolveHandler($handler);
        return $this;
    }

    // 解析最终处理器（与 addRoute 保持一致）
    private function resolveHandler(mixed $handler): array
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            return explode('@', $handler, 2);
        }
        if ($this->context->controller !== '' && is_string($handler)) {
            return [$this->context->controller, $handler];
        }
        return $handler;
    }

    // 注册路由
    public function addRoute(array | string $method, string $path, mixed $handler): Route
    {
        // 组装路径/回调
        $prefix      = $this->context->prefix;
        $controller  = $this->context->controller;
        $middlewares = $this->context->getMiddleware();
        $fullPath    = "{$prefix}{$path}";

        if (is_string($handler) && str_contains($handler, '@')) {
            $finalHandler = explode('@', $handler, 2);

        } elseif ($controller !== '' && is_string($handler)) {
            $finalHandler = [$controller, $handler];

        } else {
            $finalHandler = $handler;
        }

        // 创建路由
        $route = new Route(
            $method,
            $fullPath,
            $finalHandler,
            $middlewares
        );

        // 冲突检测：同 path + 同 method（ANY 视为通配）
        $this->guardConflict($route);

        // 恢复到当前组的基准快照
        $this->context = $this->baseSnapshot->getSnapshot();

        // 记录到路由表并加入前缀树
        $this->routes ??= $this->collectRoutes();
        $this->routes[] = $route;
        $this->insert($route);

        return $route;
    }

    // 取前缀树节点形态（缓存装载后是数组，要注册新路由时先还原成节点树）
    private function rootNode(): TrieNode
    {
        if (is_array($this->root)) {
            $this->root = TrieNode::fromArray($this->root);
        }

        return $this->root;
    }

    // 将路由条目插入前缀树（实例或缓存数组均可）
    private function insert(array | Route $route): void
    {
        $curr     = $this->rootNode();
        $segments = array_filter(explode('/', $route['path']), fn($s) => $s !== '');

        foreach ($segments as $seg) {
            if (preg_match(self::PARAM_PATTERN, $seg, $m)) {
                $curr->param ??= new TrieNode();
                $curr = $curr->param;
            } else {
                $curr->static[$seg] ??= new TrieNode();
                $curr = $curr->static[$seg];
            }
        }

        // 同 path 下允许多方法共存，按 path + methods 生成唯一键
        $key = $route['path'] . "\0" . implode(',', $route['methods']);
        $curr->candidates[$key] = $route;
    }

    // 冲突检测：同一 path 上出现重复 method（ANY 与任意方法互斥）
    private function guardConflict(Route $route): void
    {
        $path = $route['path'];

        foreach ($route['methods'] as $method) {
            $method = strtoupper((string) $method);

            $conflicts = ($method === self::ANY_METHOD)
                ? array_keys($this->occupied[$path] ?? [])
                : array_values(array_filter(
                    [$method, self::ANY_METHOD],
                    fn($key) => isset($this->occupied[$path][$key])
                ));

            if (! empty($conflicts)) {
                throw RouteConflictException::make(
                    $path,
                    $method,
                    (string) ($this->occupied[$path][$conflicts[0]] ?? '-'),
                    $this->describeHandler($route['finalHandler'])
                );
            }

            $this->occupied[$path][$method] = $this->describeHandler($route['finalHandler']);
        }
    }

    // 处理器可读描述（用于冲突提示）
    private function describeHandler(mixed $handler): string
    {
        if (is_array($handler)) {
            $parts = array_map(
                static fn($part) => is_string($part) ? $part : get_debug_type($part),
                $handler
            );
            return implode('@', $parts);
        }

        return is_string($handler) ? $handler : get_debug_type($handler);
    }

    // 注册路由分组
    public function group(callable $callback): self
    {
        // 注册前先保证前缀树为节点形态（缓存装载后是数组，克隆只共享节点对象）
        $this->rootNode();

        // 完整克隆实例
        $subRouter = clone $this;

        // 上下文单独克隆切断引用
        $subRouter->context = clone $this->context;

        // 路由组基准快照
        $subRouter->baseSnapshot = clone $subRouter->context;

        // 回调传入克隆实例
        $callback($subRouter);

        // 向上覆盖路由表与占位表
        $this->routes   = $subRouter->routes;
        $this->occupied = $subRouter->occupied;

        // 组结束：恢复当前上下文，避免前缀/中间件外泄到后续注册
        $this->context = $this->baseSnapshot->getSnapshot();

        return $this;
    }

    // 派遣路由
    public function dispatch(ServerRequestInterface $request, callable $onMismatch = null): array
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();

        $list = $this->match($path);

        $support = [];
        if (! empty($list)) {
            foreach ($list as $item) {
                $route   = $item['route'];
                $params  = $item['params'];
                $methods = $route['methods'];
                $support = array_merge($support, $methods);

                // HEAD 语义上等同于 GET（只是不返回响应体），未显式声明 HEAD 时回落到 GET 路由
                if (
                    in_array($method, $methods, true) ||
                    ($method === 'HEAD' && in_array('GET', $methods, true)) ||
                    in_array('ANY', $methods, true)
                ) {
                    return [$route, $params];
                }
            }
            $support = array_flip($support);
            unset($support['ANY']);
            $support = array_flip($support);

            if (
                $method === 'OPTIONS' &&
                is_callable($onMismatch)
            ) {
                $virtual = new Route(
                    'OPTIONS',
                    $path,
                    function () use ($onMismatch, $support) {
                        return $onMismatch($support);
                    }
                );
                return [
                    $virtual,
                    [$support],
                ];
            }
            // 没有匹配到方法
            throw new MethodNotAllowed(405);
        } else {
            // 没有匹配到路径：若有兜底处理器则交给它（自定义 404 页），否则抛出 404
            if ($this->fallback !== null) {
                $route = new Route(
                    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                    $path,
                    $this->fallback
                );
                return [$route, []];
            }
            throw new RouteNotFound(404);
        }
    }

    // 预检请求
    public function options(ServerRequestInterface $request): array
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();

        $support = [''];
        $list    = $this->match($path);
        if (! empty($list)) {
            // 搜集支持的方法
            foreach ($list as $item) {
                $route   = $item['route'];
                $params  = $item['params'];
                $methods = $route['methods'];
                $support = array_merge($support, $methods);
            }
        } else {
            // 没有匹配到路径
            throw new RouteNotFound(404);
        }
        return in_array('ANY', $support, true) ? self::ALL_HTTP_METHODS : $support;
    }

    // 匹配路由
    private function match(string $path): array
    {
        $segments = array_filter(explode('/', $path), fn($s) => $s !== '');
        $curr     = $this->root;

        // 前缀树快速查找（节点树与缓存数组共用同一套槽位取值）
        foreach ($segments as $reqSeg) {
            $static = $curr[TrieNode::STATIC] ?? [];
            if (isset($static[$reqSeg])) {
                $curr = $static[$reqSeg];
                continue;
            }
            if (($curr[TrieNode::PARAM] ?? null) !== null) {
                $curr = $curr[TrieNode::PARAM];
                continue;
            }
            return [];
        }

        // 正则精确查找
        $list = [];
        foreach ($curr[TrieNode::CANDIDATES] ?? [] as $route) {
            $regex = $route['pattern'];
            // 增加 $matches 接收捕获分组
            if (preg_match($regex, $path, $matches)) {

                // 丢掉下标0
                $values = array_slice($matches, 1);
                // 替换参数名
                $params = array_combine($route['paramNames'], $values);

                $rules = $route['paramRules'];

                // 最终验证
                foreach ($rules as $name => $pcre) {
                    if (! preg_match("#^{$pcre}$#", $params[$name])) {
                        continue 2;
                    }
                }

                $list[] = [
                    'route'  => $route,
                    'params' => $params,
                ];
            }
        }

        return $list;
    }

    // 代理上下文
    // 添加路由前缀（拼接追加）
    public function prefix(string $prefix): self
    {
        $this->context->prefix($prefix);
        return $this;
    }

    // 设置控制器
    public function controller(string $controller): self
    {
        $this->context->controller($controller);
        return $this;
    }

    // 覆盖当前的中间件列表
    public function middleware(array | string $middleware): self
    {
        $this->context->middleware($middleware);
        return $this;
    }

    // 从当前列表移除中间件
    public function withoutMiddleware(array | string $middleware): self
    {
        $this->context->withoutMiddleware($middleware);
        return $this;
    }

    // 添加中间件到列表的末尾
    public function appendMiddleware(array | string $middleware): static
    {
        $this->context->appendMiddleware($middleware);
        return $this;
    }

    // 将中间件添加到列表的开头
    public function prependMiddleware(array | string $middleware): self
    {
        $this->context->prependMiddleware($middleware);
        return $this;
    }

}
