<?php


declare (strict_types = 1);

return [
    'priority'  => 20,
    'bindings'  => [
        \Sharky\Http\Routing\Router::class                     => [
            'concrete' => \Sharky\Http\Routing\Router::class,
            'shared'   => true,
        ],
        // 未实现 RequestHandlerInterface
        // \Psr\Http\Server\RequestHandlerInterface::class => [
        //     'concrete' => \Psr\Http\Server\RequestHandlerInterface::class,
        //     'shared' => false,
        // ],

        // PSR7 接口实现
        \Psr\Http\Message\ServerRequestInterface::class        => [
            'concrete' => \Sharky\Http\Message\ServerRequest::class,
            'shared'   => false,
        ],
        \Psr\Http\Message\ResponseInterface::class             => [
            'concrete' => \Sharky\Http\Message\Response::class,
            'shared'   => false,
        ],
        \Psr\Http\Message\UriInterface::class                  => [
            'concrete' => \Sharky\Http\Message\Uri::class,
            'shared'   => false,
        ],
        \Psr\Http\Message\StreamInterface::class               => [
            'concrete' => \Sharky\Http\Message\Stream::class,
            'shared'   => false,
        ],
        \Psr\Http\Message\UploadedFileInterface::class         => [
            'concrete' => \Sharky\Http\Message\UploadedFile::class,
            'shared'   => false,
        ],
        \Psr\Http\Message\RequestInterface::class              => [
            'concrete' => \Sharky\Http\Message\Request::class,
            'shared'   => false,
        ],

        // PSR17 工厂接口实现
        \Psr\Http\Message\RequestFactoryInterface::class       => [
            'concrete' => \Sharky\Http\Factory\HttpFactory::class,
            'shared'   => true,
        ],
        \Psr\Http\Message\ResponseFactoryInterface::class      => [
            'concrete' => \Sharky\Http\Factory\HttpFactory::class,
            'shared'   => true,
        ],
        \Psr\Http\Message\ServerRequestFactoryInterface::class => [
            'concrete' => \Sharky\Http\Factory\HttpFactory::class,
            'shared'   => true,
        ],
        \Psr\Http\Message\UriFactoryInterface::class           => [
            'concrete' => \Sharky\Http\Factory\HttpFactory::class,
            'shared'   => true,
        ],
        \Psr\Http\Message\StreamFactoryInterface::class        => [
            'concrete' => \Sharky\Http\Factory\HttpFactory::class,
            'shared'   => true,
        ],
        \Psr\Http\Message\UploadedFileFactoryInterface::class  => [
            'concrete' => \Sharky\Http\Factory\HttpFactory::class,
            'shared'   => true,
        ],
    ],
    'listeners' => [],
];
