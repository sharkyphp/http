<?php
/**
 * SharkyPHP Http Library
 *
 * 上传文件 实现 PSR-7 StreamInterface
 *
 * @package Sharky\Http
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Http\Message;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

class UploadedFile implements UploadedFileInterface
{
    protected bool $moved = false;

    /**
     * @param string|resource $streamOrFile 字符串=文件路径，resource=打开的流资源
     * @param int $size
     * @param int $error UPLOAD_ERR_* 常量
     * @param string|null $clientFilename
     * @param string|null $clientMediaType
     */
    public function __construct(
        protected $streamOrFile,
        protected int $size,
        protected int $error,
        protected ?string $clientFilename = null,
        protected ?string $clientMediaType = null
    ) {}

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException(sprintf('Uploaded file has already been moved, cannot retrieve stream.'));
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf('Cannot get stream for an uploaded file with upload error.'));
        }

        if ($this->streamOrFile instanceof StreamInterface) {
            return $this->streamOrFile;
        }
        if (is_resource($this->streamOrFile)) {
            return new Stream($this->streamOrFile);
        }
        return Stream::createFromFile($this->streamOrFile, 'r+');
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException(sprintf('Uploaded file has already been moved.'));
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf('Cannot move an uploaded file with upload error.'));
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            throw new RuntimeException(sprintf('Target directory "%s" cannot be created.', $targetDir));
        }
        if (!is_writable($targetDir)) {
            throw new RuntimeException(sprintf('Target directory "%s" is not writable.', $targetDir));
        }

        $isSapiUpload = is_string($this->streamOrFile) && is_uploaded_file($this->streamOrFile);
        $ok = $isSapiUpload ? move_uploaded_file($this->streamOrFile, $targetPath) : rename($this->streamOrFile, $targetPath);

        if (!$ok) {
            throw new RuntimeException(sprintf('Failed to move uploaded file to "%s".', $targetPath));
        }
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
