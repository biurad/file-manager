<?php

declare(strict_types=1);

/*
 * This code is under BSD 3-Clause "New" or "Revised" License.
 *
 * PHP version 7 and above required
 *
 * @category  FileManager
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2019 Biurad Group (https://biurad.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * @link      https://www.biurad.com/projects/filemanager
 * @since     Version 0.1
 */

namespace BiuradPHP\FileManager\Streams;

use BiuradPHP\FileManager\Exception\WrapperException;
use BiuradPHP\FileManager\Interfaces\FileManagerInterface;
use BiuradPHP\FileManager\Interfaces\StreamInterface;
use Exception;
use League\Flysystem\Adapter\Local as FlyLocal;
use League\Flysystem\Cached\CachedAdapter;
use League\Flysystem\FileNotFoundException;
use LogicException;
use SplFileObject;

class FlyStreamBuffer implements StreamInterface
{
    private $filesystem;
    private $key;
    private $mode;
    private $content;
    private $numBytes;
    private $position;
    private $synchronized;

    /**
     * @param FileManagerInterface $filesystem The filesystem managing the file to stream
     * @param string $key The file key
     */
    public function __construct(FileManagerInterface $filesystem, $key)
    {
        $this->filesystem = $filesystem;
        $this->key = $key;
    }

    /**
     * {@inheritdoc}
     * @throws FileNotFoundException
     */
    public function open(StreamMode $mode)
    {
        $this->mode = $mode;

        if (true !== $exists = $this->filesystem->has($this->key)) {
            return false;
        }

        if (($exists && !$mode->allowsExistingFileOpening())
            || (!$exists && !$mode->allowsNewFileOpening())) {
            return false;
        }

        if ($mode->impliesExistingContentDeletion()) {
            $this->content = $this->writeContent('');
        } elseif (!$exists && $mode->allowsNewFileOpening()) {
            $this->content = $this->writeContent('');
        } else {
            $this->content = $this->filesystem->isDirectory($this->key) ? '' : $this->filesystem->read($this->key);
        }

        $this->numBytes = mb_strlen($this->content, '8bit');
        $this->position = $mode->impliesPositioningCursorAtTheEnd() ? $this->numBytes : 0;

        $this->synchronized = true;

        return true;
    }

    public function read($count)
    {
        if (false === $this->mode->allowsRead()) {
            throw new LogicException('The stream does not allow read.');
        }

        $chunk = substr($this->content, $this->position, $count);
        $this->position += mb_strlen($chunk, '8bit');

        return $chunk;
    }

    public function write($data)
    {
        if (false === $this->mode->allowsWrite()) {
            throw new LogicException('The stream does not allow write.');
        }

        $numWrittenBytes = mb_strlen($data, '8bit');

        $newPosition = $this->position + $numWrittenBytes;
        $newNumBytes = $newPosition > $this->numBytes ? $newPosition : $this->numBytes;

        if ($this->eof()) {
            $this->numBytes += $numWrittenBytes;
            if ($this->hasNewContentAtFurtherPosition()) {
                $data = str_pad($data, $this->position + strlen($data), ' ', STR_PAD_LEFT);
            }
            $this->content .= $data;
        } else {
            $before = substr($this->content, 0, $this->position);
            $after = $newNumBytes > $newPosition ? substr($this->content, $newPosition) : '';
            $this->content = $before.$data.$after;
        }

        $this->position = $newPosition;
        $this->numBytes = $newNumBytes;
        $this->synchronized = false;

        return $numWrittenBytes;
    }

    public function close()
    {
        if (!$this->synchronized) {
            $this->flush();
        }
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        switch ($whence) {
            case SEEK_SET:
                $this->position = $offset;
                break;
            case SEEK_CUR:
                $this->position += $offset;
                break;
            case SEEK_END:
                $this->position = $this->numBytes + $offset;
                break;
            default:
                return false;
        }

        return true;
    }

    public function tell()
    {
        return $this->position;
    }

    public function flush()
    {
        if ($this->synchronized) {
            return true;
        }

        try {
            $this->writeContent($this->content);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public function eof()
    {
        return $this->position >= $this->numBytes;
    }

    /**
     * {@inheritdoc}
     * @throws FileNotFoundException
     */
    public function stat()
    {
        if ($this->filesystem->has($this->key)) {
            $isDirectory = $this->filesystem->isDirectory($this->key);
            $time = $this->filesystem->getTimestamp($this->key);
            $path = $this->filesystem->path($this->key);
            $isLocal = $this->isLocalAdapter();
            $mode = ! $isDirectory ? (new SplFileObject($path))->fstat()['mode'] : 16893;

            $stats = [
                'dev' => 1,
                'ino' => 0,
                'mode' => !$isLocal ? ($isDirectory ? 16893 : 33204) : $mode,
                'nlink' => 1,
                'uid' => 0,
                'gid' => 0,
                'rdev' => 0,
                'size' => $isDirectory ? 0 : $this->filesystem->getSize($this->key),
                'atime' => !$isLocal ? $time : fileatime($path),
                'mtime' => $time,
                'ctime' => !$isLocal ? $time : filectime($path),
                'blksize' => -1,
                'blocks' => -1,
            ];

            return array_merge(array_values($stats), $stats);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function cast($castAst)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function unlink()
    {
        if ($this->mode && $this->mode->impliesExistingContentDeletion()) {
            return $this->filesystem->delete($this->key);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function opendir($path)
    {
        if ($this->isLocalAdapter()) {
            return opendir($this->filesystem->path($this->key));
        }

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function readdir()
    {
        try {
            return $this->filesystem->readStream($this->key);
        } catch (Exception $e) {
            throw new WrapperException('Sorry, doesn\'t support reading directory on remote connection, use local storage instead.', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir()
    {
        return $this->filesystem->createDir($this->key);
    }

    /**
     * {@inheritdoc}
     */
    public function rmdir()
    {
        return $this->filesystem->deleteDir($this->key);
    }

    /**
     * @return bool
     */
    protected function isLocalAdapter()
    {
        if (($adapter = $this->filesystem->getAdapter()) instanceof CachedAdapter) {
            $adapter = $adapter->getAdapter();
        }

        if ($adapter instanceof FlyLocal) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected function hasNewContentAtFurtherPosition()
    {
        return $this->position > 0 && !$this->content;
    }

    /**
     * @param string $content   Empty string by default
     *
     * @return string
     */
    protected function writeContent($content = '')
    {
        $this->filesystem->put($this->key, $content);

        return $content;
    }
}