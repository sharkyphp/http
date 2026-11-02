<?php
/**
 * SharkyPHP Http Library
 *
 * HttpFactory
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Factory;

use BadMethodCallException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

final class HttpFactory implements
RequestFactoryInterface,
ResponseFactoryInterface,
ServerRequestFactoryInterface,
StreamFactoryInterface,
UploadedFileFactoryInterface,
UriFactoryInterface
{

    use RequestTrait;
    use ResponseTrait;
    use ServerRequestTrait;
    use UriTrait;
    use StreamTrait;
    use UploadedFileTrait;
    private ?object $lazyInstance = null;

    public function __construct(
        private string $targetTag = ''
    ) {}

    public function __call(string $method, array $args)
    {
        $target = $this->getLazyInstance();

        if (! method_exists($target, $method)) {
            throw new BadMethodCallException(
                sprintf('Method "%s" does not exist on %s', $method, $this->targetTag)
            );
        }

        return $target->$method(...$args);
    }

    // 设置 __call 转发
    public function setFactory(string $targetTag = '')
    {
        $this->targetTag = $targetTag;
        return $this;
    }

    private function getLazyInstance(): object
    {
        if ($this->lazyInstance === null) {
            $this->lazyInstance = match ($this->targetTag) {
                'response'      => $this->createResponse(),
                'serverRequest' => $this->serverRequestCapture(),
                'request'       => $this->createRequest('GET', '/'),
                'uri'           => $this->createUri('/'),
                'stream'        => $this->createStream(''),
                'uploadedFile'  => $this->createUploadedFile($this->createStream('')),
                default         => throw new InvalidArgumentException("unknown target tag: {$this->targetTag}")
            };
        }
        return $this->lazyInstance;
    }
}
