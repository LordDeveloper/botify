<?php

namespace Botify\Utils;

use Amp\Promise;
use function Amp\File\{deleteFile,move,write,read};
use function Botify\abs_path;


class FileSystem
{
    /**
     * @var string
     */
    private string $absolutePath;
    /**
     * @var string
     */
    private string $baseName;
    /**
     * @var string
     */
    private string $dirName;
    /**
     * @var bool
     */
    private bool $exists;
    /**
     * @var string
     */
    private string $extension;
    /**
     * @var bool
     */
    private bool $isDir;
    /**
     * @var string
     */
    private string $path;
    /**
     * @var bool
     */
    private bool $readable;
    /**
     * @var bool
     */
    private bool $writeable;

    /**
     * @param string $path
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->baseName = basename($path);
        $this->dirName = dirname($path);
        $this->extension = pathinfo($path, PATHINFO_EXTENSION);
        $this->absolutePath = abs_path($path);
        $this->exists = file_exists($path);
        $this->isDir = is_dir($path);
        $this->writeable = is_writable($path);
        $this->readable = is_readable($path);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->path;
    }

    /**
     * @return Promise
     */
    public function delete(): Promise
    {
        return deleteFile($this->path);
    }

    /**
     * @return mixed|string
     */
    public function getAbsolutePath(): mixed
    {
        return $this->absolutePath;
    }

    /**
     * @return string
     */
    public function getBaseName(): string
    {
        return $this->baseName;
    }

    /**
     * @return string
     */
    public function getDirName(): string
    {
        return $this->dirName;
    }

    /**
     * @return string
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return bool
     */
    public function isDir(): bool
    {
        return $this->isDir;
    }

    /**
     * @return bool
     */
    public function isExists(): bool
    {
        return $this->exists;
    }

    /**
     * @return bool
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * @return bool
     */
    public function isWriteable(): bool
    {
        return $this->writeable;
    }

    /**
     * @param $to
     * @return Promise
     */
    public function rename($to): Promise
    {
        return $this->move($to);
    }

    /**
     * @param $to
     * @return Promise
     */
    public function move($to): Promise
    {
        return move($this->path, $to);
    }

    /**
     * @param string $contents
     * @return Promise
     */
    public function put(string $contents): Promise
    {
        return write($this->path,$contents);
    }

    /**
     * @return Promise 
     */
    public function get(): Promise
    {
        return read($this->path);
    }
}