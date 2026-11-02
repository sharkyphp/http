<?php
/**
 * SharkyPHP Http Library
 *
 * 路由缓存文件（项目 runtime/routes.php）
 *
 * 生产环境优先加载缓存，避免解析路由声明文件与扫描注解；
 * 缓存内容是编译后的整棵前缀树，序列化后 base64 落盘，由 route:compile 生成。
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

use RuntimeException;
use Sharky\Facade\Config;
use Sharky\Facade\Container;
use Sharky\Support\Helper;
use Throwable;

final class RouteCache
{
    // 缓存文件名
    public const FILE_NAME = 'routes.php';

    // 默认注解扫描：命名空间 => 相对项目目录
    public const SCAN_NAMESPACE = 'App\\Http\\Controllers';
    public const SCAN_DIRECTORY = 'app/Http/Controllers';

    /**
     * 缓存文件绝对路径
     *
     * 优先读取 http.routing.cache 配置，缺省落在项目 runtime 目录下。
     */
    public static function file(?string $file = null): string
    {
        if ($file === null) {
            $configured = Config::get('http.routing.cache');
            $file       = is_string($configured) ? $configured : '';
        }

        if ($file !== '') {
            return Helper::realath($file);
        }

        return Helper::realath(self::directory() . '/' . self::FILE_NAME);
    }

    // 项目 runtime 目录
    public static function directory(): string
    {
        return Helper::realath(self::projectDirectory() . '/runtime');
    }

    // 项目根目录（容器门面）
    public static function projectDirectory(): string
    {
        try {
            $directory = (string) Container::get('project.directory');
        } catch (Throwable) {
            $directory = '';
        }

        if ($directory === '') {
            throw new RuntimeException('Project directory is missing, container binding [project.directory] is not available.');
        }

        return $directory;
    }

    // 缓存文件是否存在
    public static function exists(?string $file = null): bool
    {
        return is_file(self::file($file));
    }

    /**
     * 读取缓存内容
     *
     * 新版文件返回 `base64(serialize([...]))` 字符串，这里解码成数组；
     * 旧版文件直接返回数组（扁平路由列表或嵌套前缀树）。
     *
     * @return array<string|int, mixed>
     */
    public static function load(?string $file = null): array
    {
        $file = self::file($file);

        if (! is_file($file)) {
            return [];
        }

        $data = require $file;

        // 新版：字符串载荷，解码后才是数据（损坏时 unserialize 会告警，这里统一转成可读异常）
        if (is_string($data)) {
            $raw = base64_decode($data, true);

            try {
                $data = $raw === false ? false : unserialize($raw, ['allowed_classes' => false]);
            } catch (Throwable) {
                $data = false;
            }
        }

        if (! is_array($data)) {
            throw new RuntimeException(sprintf(
                'Route cache is corrupted: %s. Please run "php sharky route:compile" to rebuild it.',
                $file
            ));
        }

        return $data;
    }

    // 删除缓存文件
    public static function flush(?string $file = null): bool
    {
        $file = self::file($file);

        return is_file($file) ? @unlink($file) : false;
    }

    /**
     * 导出路由缓存（原子写入）
     *
     * 内容为整棵前缀树（紧凑索引数组），序列化后 base64 落成 PHP 文件：
     * `require` 拿到字符串 → `unserialize` 还原数组，比逐字面量编译数组快一倍。
     * 装载时直接赋值给 Router，不需要重建节点、路由实例或正则。
     *
     * @param array<string, mixed> $payload 传 `['trie' => ...]`，也兼容旧版扁平路由列表
     * @return string 缓存文件绝对路径
     */
    public static function dump(array $payload, ?string $file = null): string
    {
        $file = self::file($file);
        $dir  = dirname($file);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Route cache directory is not writable: %s', $dir));
        }

        self::validate($payload);

        $body = base64_encode(serialize($payload));

        // 先写临时文件，写完再替换，避免生成过程中被读到半截内容
        $tempFile = sprintf('%s.%s.tmp', $file, bin2hex(random_bytes(4)));
        $fp       = @fopen($tempFile, 'wb');

        if ($fp === false) {
            throw new RuntimeException(sprintf('Unable to write route cache file: %s', $file));
        }

        $header = "<?php\n"
            . "/**\n"
            . " * SharkyPHP 路由缓存文件\n"
            . " *\n"
            . " * 该文件由 route:compile 命令生成，请勿手动修改；\n"
            . " * 内容为编译后的整棵路由前缀树（base64 + serialize），仅供 Router 装载使用。\n"
            . " */\n\n"
            . "declare (strict_types = 1);\n\n"
            . "return '";

        fwrite($fp, $header);
        fwrite($fp, $body);
        fwrite($fp, "';\n");
        fclose($fp);

        // Windows 下 rename 不允许覆盖已存在文件
        if (is_file($file)) {@unlink($file);}

        if (! @rename($tempFile, $file)) {
            @unlink($tempFile);
            throw new RuntimeException(sprintf('Unable to replace route cache file: %s', $file));
        }

        return $file;
    }

    /**
     * 默认注解扫描目录 [命名空间 => 目录]
     *
     * @return array<string, string>
     */
    public static function defaultScan(): array
    {
        $configured = Config::get('http.routing.scan', []);

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [self::SCAN_NAMESPACE => Helper::realath(self::projectDirectory() . '/' . self::SCAN_DIRECTORY)];
    }

    /**
     * 校验路由是否可被缓存导出
     *
     * 闭包/对象实例处理器无法写入 PHP 数组文件，直接给出可读的错误。
     *
     * @param array<string|int, mixed> $payload
     */
    private static function validate(array $payload): void
    {
        foreach (self::flatten($payload) as $route) {
            $path    = (string) ($route['path'] ?? '');
            $handler = $route['finalHandler'] ?? null;

            if (! self::isExportable($handler)) {
                throw new RuntimeException(sprintf(
                    'Route [%s] cannot be cached, handler must be a string or [class, method] array, [%s] given.',
                    $path,
                    get_debug_type($handler)
                ));
            }

            foreach ((array) ($route['middlewares'] ?? []) as $middleware) {
                if (! is_string($middleware)) {
                    throw new RuntimeException(sprintf(
                        'Route [%s] cannot be cached, middleware must be a string, [%s] given.',
                        $path,
                        get_debug_type($middleware)
                    ));
                }
            }
        }
    }

    // 展开 payload 中的全部路由条目（新版嵌套前缀树 / 旧版扁平列表）
    private static function flatten(array $payload): iterable
    {
        if (isset($payload['trie']) && is_array($payload['trie'])) {
            yield from TrieNode::flatten($payload['trie']);
            return;
        }

        foreach ($payload as $route) {
            if (is_array($route)) {
                yield $route;
            }
        }
    }

    // 处理器是否可以导出为 PHP 数组（字符串或 [类, 方法]）
    private static function isExportable(mixed $handler): bool
    {
        if (is_string($handler)) {
            return true;
        }

        return is_array($handler)
            && array_is_list($handler)
            && count($handler) === 2
            && is_string($handler[0])
            && is_string($handler[1]);
    }
}
