<?php
/**
 * SharkyPHP Http Library
 *
 * Cookie管理器
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Cookie;

final class Cookie
{
    // 设置一个 cookie
    public static function set(
        $key,
        $value = '',
        $expires = 3600,
        $path = "",
        $domain = "",
        $secure = false,
        $httponly = true
    ) {
        setcookie($key, $value, time() + $expiry, $path, $domain, $secure, $httponly);
    }

    // 获取 cookie 的值
    public static function get($key, $default = null)
    {
        return $_COOKIE[$key] ?? $default;
    }

    // 检查 cookie 是否存在
    public static function has($key)
    {
        return isset($_COOKIE[$key]);
    }

    // 删除 cookie
    public static function delete($key, $path = "/", $domain = "")
    {
        if (isset($_COOKIE[$key])) {
            setcookie($key, '', time() - 3600, $path, $domain);
            unset($_COOKIE[$key]);
        }
    }
}
