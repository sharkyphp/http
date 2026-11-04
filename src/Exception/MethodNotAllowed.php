<?php
/**
 * SharkyPHP Http Library
 *
 * 方法不允许
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 */
declare (strict_types = 1);

namespace Sharky\Http\Exception;

class MethodNotAllowed extends HttpException
{
    public function __construct(int $statusCode = 405, string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($statusCode, $message, $code, $previous);
        $this->statusCode = $statusCode;
    }
}
