<?php
/**
 * SharkyPHP Http Library
 *
 * 响应工厂
 *
 * @package SharkyPHP\\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Http\Factory;

use InvalidArgumentException;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Sharky\Http\Message\Response;
use SimpleXMLElement;

trait ResponseTrait
{
    public function response(string $body = '', int $statusCode = 200, array $headers = []): ResponseInterface
    {
        $stream   = $this->createStream($body ?? '');
        $response = $this->createResponse($statusCode)
            ->setBody($stream)
            ->setHeader($headers);
        return $response;
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $emptyStream = $this->createStream('');
        return new Response($code, [], $emptyStream);
    }

    public function json(mixed $body, int $statusCode = 200, array $headers = []): ResponseInterface
    {
        [$payload, $contentType] = $this->prepareBody($body, function ($data) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(sprintf("JSON serialization failed: %s", json_last_error_msg()));
            }
            return [$json, 'application/json; charset=utf-8'];
        }, 'json');
        // 强校验：调用json() 必须产出JSON
        if (! str_starts_with($contentType, 'application/json')) {
            throw new InvalidArgumentException(
                sprintf("JSON response contract failed: input type cannot be converted to JSON.")
            );
        }

        $response = $this->createResponse($statusCode)
            ->setBody($payload)
            ->setHeader($headers)
            ->setContentType($contentType);
        return $response;
    }

    public function xml(mixed $body, int $statusCode = 200, array $headers = []): ResponseInterface
    {
        [$payload, $contentType] = $this->prepareBody($body, function ($data) {
            if (! is_array($data) || count($data) !== 1) {
                throw new InvalidArgumentException(
                    sprintf("XML data must be an array containing exactly one root node")
                );
            }
            $xmlRoot    = array_key_first($data);
            $xmlData    = $data[$xmlRoot];
            $xmlElement = $this->arrayToXml($xmlData, $xmlRoot);
            return [$xmlElement->asXML(), 'application/xml; charset=utf-8'];
        }, 'xml');
        // 强校验：调用xml() 必须产出XML
        if (! str_starts_with($contentType, 'application/xml')) {
            throw new InvalidArgumentException(
                sprintf("XML response contract failed: input type cannot be converted to XML.")
            );
        }

        $response = $this->createResponse($statusCode)
            ->setBody($payload)
            ->setHeader($headers)
            ->setContentType($contentType);
        return $response;
    }

    public function html(mixed $body, int $statusCode = 200, array $headers = []): ResponseInterface
    {
        if (is_object($body)) {
            if (method_exists($body, '__toString')) {
                $body = (string) $body;
            } else {
                throw new InvalidArgumentException(
                    sprintf("HTML response only supports string, null, or objects implementing __toString")
                );
            }
        } elseif ($body === null) {
            $body = '';
        }
        if (! is_string($body)) {
            $givenType = gettype($body);
            if ($givenType === 'object') {
                $givenType = get_class($body);
            }
            throw new InvalidArgumentException(
                sprintf("Unsupported body type for HTML response, given: %s", $givenType)
            );
        }

        $contentType = 'text/html; charset=utf-8';
        $response    = $this->createResponse($statusCode)
            ->setBody($this->createStream($body))
            ->setHeader($headers)
            ->setContentType($contentType);
        return $response;
    }

    public function redirect($url, $status = 302): ResponseInterface
    {
        if (preg_match('/https?:\/\/.+/', trim($url))) {
            $this->setHeader(['Location' => $url]);
            $this->setStatus($status);
            return $this;
        } else {
            throw new InvalidArgumentException(sprintf("Redirect URL is not valid"));
        }
    }

    /**
     * 预处理主体
     * @param callable $arrayhandle 仅数组/toArray/JsonSerializable结果才执行
     * @param string $format json|xml，仅控制toJson分支，不传入回调
     * @return array{0: string|StreamInterface,1:string}
     */
    private function prepareBody(mixed $body, callable $arrayhandle, string $format): array
    {
        $payload     = null;
        $contentType = 'text/html; charset=utf-8';

        if (is_string($body)) {
            $payload = $body;
        } elseif (is_array($body)) {
            [$payload, $contentType] = $arrayhandle($body);
        } elseif (is_resource($body)) {
            $payload = $this->createStream($body);
        } elseif (is_object($body)) {
            if (method_exists($body, '__toString')) {
                $payload = (string) $body;
            } elseif ($format === 'json' && method_exists($body, 'toJson')) {
                $ref = new ReflectionMethod($body, 'toJson');
                if ($ref->getNumberOfRequiredParameters() === 0) {
                    $payload     = $body->toJson();
                    $contentType = 'application/json; charset=utf-8';
                } else {
                    throw new InvalidArgumentException(
                        sprintf("toJson has required parameters and cannot be auto-converted")
                    );
                }
            } elseif (method_exists($body, 'toArray')) {
                $ref = new ReflectionMethod($body, 'toArray');
                if ($ref->getNumberOfRequiredParameters() === 0) {
                    $arr                     = $body->toArray();
                    [$payload, $contentType] = $arrayhandle($arr);
                } else {
                    throw new InvalidArgumentException(
                        sprintf("toArray has required parameters and cannot be auto-converted")
                    );
                }
            } elseif ($body instanceof JsonSerializable) {
                $raw                     = $body->jsonSerialize();
                [$payload, $contentType] = $arrayhandle($raw);
            } else {
                throw new InvalidArgumentException(
                    sprintf("Unsupported object, no available conversion method (__toString/toArray/toJson/JsonSerializable)")
                );
            }
        } elseif ($body === null) {
            $payload = '';
        } else {
            $type = gettype($body);
            if ($type === 'object') {
                $type = get_class($body);
            }
            throw new InvalidArgumentException(sprintf("Unsupported object: %s", $type));
        }

        return [
            $this->createStream($payload ?? ''),
            $contentType,
        ];
    }

    private function arrayToXml($data, string $root = 'root'): SimpleXMLElement
    {
        // 校验根标签名，防止注入
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $root)) {
            throw new InvalidArgumentException(sprintf('Invalid xml root tag name'));
        }

        $data     = is_array($data) ? $data : [$data];
        $xmlStr   = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$root}></{$root}>";
        $rootNode = new SimpleXMLElement($xmlStr);

        $toXml = function ($data, &$parentNode) use (&$toXml) {
            foreach ($data as $key => $value) {
                // 数字key，转成item-xxx
                if (is_numeric($key)) {
                    $key = "item-{$key}";
                }
                // 校验标签名合法性
                if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $key)) {
                    throw new InvalidArgumentException(
                        sprintf('Invalid xml tag name: "%s", tag name cannot be empty or contain invalid characters', $key)
                    );
                }

                if (is_array($value)) {
                    $subNode = $parentNode->addChild($key);
                    $toXml($value, $subNode);
                } else {
                    $strVal = (string) $value;
                    // XML专用转义 ENT_XML1
                    $parentNode->addChild($key, htmlspecialchars($strVal, ENT_XML1, 'UTF-8'));
                }
            }
        };

        $toXml($data, $rootNode);
        return $rootNode;
    }
}
