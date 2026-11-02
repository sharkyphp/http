<?php
/**
 * SharkyPHP Http Library
 *
 * 路由/中间件注解扫描器
 *
 * 扫描指定目录下的类，读取 PHP8 原生属性注解：
 *  - #[Controller(name)]            类级：声明控制器名称（仅作元数据，不影响路径）
 *  - #[Route(path, method, middleware)] 方法级：声明一条路由（Get/Post/Put/Patch/Delete 为其子类）
 *  - #[Middleware(name)]            类级：对该控制器下全部路由生效；方法级：仅对该方法路由生效
 *
 * 扫描结果统一为路由声明数组，交给 Router::registerRoutes() 合并注册。
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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Sharky\Annotation\Controller;
use Sharky\Annotation\Middleware;
use Sharky\Annotation\Route as RouteAnnotation;
use Throwable;

final class RouteScanner
{
    // 未声明 method 时的默认方法
    public const DEFAULT_METHODS = ['ANY'];

    /**
     * 扫描目录集合
     *
     * @param array $sources [命名空间 => 目录] 例如 ['App\\Http\\Controllers' => '/project/app/Http/Controllers']
     * @return array<int, array{
     *     path:string, methods:array<int,string>, handler:array{0:string,1:string},
     *     middleware:array<int,string>, controller:string
     * }>
     */
    public function scan(array $sources): array
    {
        $definitions = [];

        foreach ($sources as $namespace => $directory) {
            $namespace   = trim((string) $namespace, '\\');
            $directories = is_array($directory) ? $directory : [$directory];

            foreach ($directories as $dir) {
                if (! is_dir((string) $dir)) {continue;}

                foreach ($this->collectFiles((string) $dir) as $file) {
                    $class = $this->resolveClass($file, (string) $dir, $namespace);
                    if ($class === null) {continue;}

                    foreach ($this->scanClass($class) as $definition) {
                        $definitions[] = $definition;
                    }
                }
            }
        }

        return $definitions;
    }

    /**
     * 扫描单个类的路由/中间件注解
     *
     * @return array<int, array{path:string, methods:array<int,string>, handler:array{0:string,1:string}, middleware:array<int,string>, controller:string}>
     */
    public function scanClass(string $class): array
    {
        try {
            $refClass = new ReflectionClass($class);
        } catch (Throwable) {
            return [];
        }

        // 抽象类 / 接口 / 枚举 / Trait 不参与扫描
        if ($refClass->isAbstract() || $refClass->isInterface() || $refClass->isEnum()) {
            return [];
        }

        // 类级注解
        $controllerName   = $this->controllerName($refClass);
        $classMiddlewares = $this->middlewareNames($refClass);

        $definitions = [];
        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod) {

            // 跳过继承来的方法与构造方法
            if ($refMethod->isConstructor() || $refMethod->isDestructor()) {continue;}
            if ($refMethod->getDeclaringClass()->getName() !== $refClass->getName()) {continue;}

            // 方法级路由注解（含 Get/Post/Put/Patch/Delete）
            $annotations = $this->routeAnnotations($refMethod);
            if (empty($annotations)) {continue;}

            // 方法级中间件（类级在外层，方法级在内层）
            $methodMiddlewares = $this->middlewareNames($refMethod);

            foreach ($annotations as $annotation) {
                $definitions[] = [
                    'path'       => $this->normalizePath($annotation->path),
                    'methods'    => $this->normalizeMethods($annotation->method),
                    'handler'    => [$refClass->getName(), $refMethod->getName()],
                    'middleware' => $this->mergeMiddleware($classMiddlewares, $annotation->middleware, $methodMiddlewares),
                    'controller' => $controllerName,
                ];
            }
        }

        return $definitions;
    }

    // 递归收集目录下的 php 文件
    private function collectFiles(string $directory): array
    {
        $files   = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        // 保证扫描顺序稳定
        sort($files);

        return $files;
    }

    // 根据文件相对路径推导类名
    private function resolveClass(string $file, string $directory, string $namespace): ?string
    {
        $root      = rtrim(strtr($directory, '\\', '/'), '/');
        $target    = strtr($file, '\\', '/');
        $relative  = str_starts_with($target, $root) ? substr($target, strlen($root)) : $target;
        $relative  = preg_replace('/\.php$/', '', ltrim($relative, '/'));
        $segments  = array_filter(explode('/', (string) $relative), static fn($s) => $s !== '');

        if (empty($segments)) {return null;}

        $class = ($namespace === '')
            ? implode('\\', $segments)
            : $namespace . '\\' . implode('\\', $segments);

        return class_exists($class) ? $class : null;
    }

    // 类级控制器名（未声明返回空字符串）
    private function controllerName(ReflectionClass $refClass): string
    {
        foreach ($this->attributes($refClass, Controller::class) as $instance) {
            return $instance->name;
        }
        return '';
    }

    // 读取路由注解实例（Route 及其子类）
    private function routeAnnotations(ReflectionMethod $refMethod): array
    {
        return $this->attributes($refMethod, RouteAnnotation::class);
    }

    // 读取中间件注解名称（类级/方法级）
    private function middlewareNames(ReflectionClass | ReflectionMethod $reflection): array
    {
        $middlewares = [];
        foreach ($this->attributes($reflection, Middleware::class) as $instance) {
            if ($instance->name !== '') {$middlewares[] = $instance->name;}
        }
        return $middlewares;
    }

    // 实例化注解（IS_INSTANCEOF：命中子类）
    private function attributes(ReflectionClass | ReflectionMethod $reflection, string $annotation): array
    {
        $instances = [];
        foreach ($reflection->getAttributes($annotation, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            try {
                $instances[] = $attribute->newInstance();
            } catch (Throwable) {continue;}
        }
        return $instances;
    }

    // 规范化路径：保证以 / 开头，去掉结尾斜杠
    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    // 规范化方法：大写，未声明按 ANY 处理
    private function normalizeMethods(array $methods): array
    {
        $methods = array_map(static fn($m) => strtoupper((string) $m), $methods);
        $methods = array_values(array_filter($methods, static fn($m) => $m !== ''));

        return empty($methods) ? self::DEFAULT_METHODS : array_values(array_unique($methods));
    }

    // 合并中间件：类级 -> 路由注解 -> 方法级（去重）
    private function mergeMiddleware(array ...$groups): array
    {
        $middlewares = [];
        foreach ($groups as $group) {
            foreach ((array) $group as $name) {
                $name = (string) $name;
                if ($name !== '' && ! in_array($name, $middlewares, true)) {
                    $middlewares[] = $name;
                }
            }
        }
        return $middlewares;
    }
}
