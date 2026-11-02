<?php
/**
 * SharkyPHP Http Library
 *
 * Http异常基类
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Http\Exception;

use Exception;
use Throwable;

class HttpException extends Exception
{
    protected int $statusCode;

    public function __construct(int $statusCode, string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
