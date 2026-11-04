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
 */
declare (strict_types = 1);

namespace Sharky\Http\Exception;

use UnexpectedValueException;

class HandlerReturnInvalidException extends UnexpectedValueException
{
}
