<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

use DirectoryIterator;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Engine\Channel;
use Hyperf\Watcher\Driver\AbstractDriver;
use Hyperf\Watcher\Option;

/**
 * 扫描文件驱动
 * @author Verdient。
 */
class ScanFileDriver extends AbstractDriver
{
    /**
     * 上次的修改时间集合
     * @author Verdient。
     */
    protected array $lastMtimes = [];

    /**
     * 上次的哈希值集合
     * @author Verdient。
     */
    protected array $lastHashes = [];

    /**
     * 当前的修改时间集合
     * @author Verdient。
     */
    protected array $currentMtimes = [];

    /**
     * 当前的哈希值集合
     * @author Verdient。
     */
    protected array $currentHashes = [];

    /**
     * 允许的扩展名
     * @author Verdient。
     */
    protected array $extensions = [];

    /**
     * 监控的文件夹集合
     * @author Verdient。
     */
    protected array $dirs = [];

    /**
     * 监控的文件集合
     * @author Verdient。
     */
    protected array $files = [];

    /**
     * @inheritdoc
     * @author Verdient。
     */
    public function __construct(protected Option $option, private StdoutLoggerInterface $logger)
    {
        parent::__construct($option);

        foreach ($this->option->getExt() as $extension) {
            if (substr($extension, 0, 1) === '.') {
                $extension = substr($extension, 1);
            }
            $this->extensions[$extension] = true;
        }

        $this->dirs = $this->option->getWatchDir();

        foreach ($this->dirs as $dir) {
            $this->initialDir($dir);
        }

        foreach ($this->option->getWatchFile() as $path) {
            $path = BASE_PATH . DIRECTORY_SEPARATOR . $path;
            $this->files[] = $path;
            $this->initialFile($path);
        }
    }

    /**
     * 初始化文件夹
     * @param string $dir 文件夹路径
     * @author Verdient。
     */
    protected function initialDir($dir)
    {
        foreach (new DirectoryIterator($dir) as $splFileInfo) {
            if ($splFileInfo->isFile()) {
                if (isset($this->extensions[$splFileInfo->getExtension()])) {
                    $path = $splFileInfo->getRealPath();
                    $mtime = $splFileInfo->getMTime();
                    $this->lastMtimes[$path] = $mtime;
                    $this->lastHashes[$path] = hash_file('md5', $path);
                }
            } else if ($splFileInfo->isDir() && !$splFileInfo->isDot()) {
                $this->initialDir($splFileInfo->getRealPath());
            }
        }
    }

    /**
     * 初始化文件
     * @param string $path 文件路径
     * @author Verdient。
     */
    protected function initialFile($path)
    {
        $this->lastMtimes[$path] = filemtime($path);
        $this->lastHashes[$path] = hash_file('md5', $path);
    }

    /**
     * @inheritdoc
     * @author Verdient。
     */
    public function watch(Channel $channel): void
    {
        $seconds = $this->option->getScanIntervalSeconds();
        $this->timerId = $this->timer->tick($seconds, function () use ($channel) {
            try {

                [$changedFiles, $addedFiles] = $this->scanFiles($this->files);

                foreach ($this->dirs as $dir) {
                    [$partChangedFiles, $partAddedFiles] = $this->scanDir($dir);
                    $changedFiles = [...$changedFiles, ...$partChangedFiles];
                    $addedFiles = [...$addedFiles, ...$partAddedFiles];
                }

                $deletedFiles = array_diff(array_keys($this->lastMtimes), array_keys($this->currentMtimes));

                $changedCount = count($changedFiles);

                $addedCount = count($addedFiles);

                $deletedCount = count($deletedFiles);

                $watchingLog = sprintf('%s Watching: Total:%d, Change:%d, Add:%d, Delete:%d.', self::class, count($this->currentMtimes), $changedCount, $addedCount, $deletedCount);

                $this->logger->debug($watchingLog);

                foreach ($addedFiles as $file) {
                    $channel->push($file);
                }
                if ($deletedCount === 0) {
                    foreach ($changedFiles as $file) {
                        $channel->push($file);
                    }
                } else {
                    $this->logger->warning('Delete files must be restarted manually to take effect.');
                }
                $this->lastMtimes = $this->currentMtimes;
                $this->lastHashes = $this->currentHashes;
                $this->currentMtimes = [];
                $this->currentHashes = [];
            } catch (\Throwable $e) {
                $this->logger->emergency($e);
            }
        });
    }

    /**
     * 扫描文件夹
     * @param string $dir 文件夹路径
     * @return array
     * @author Verdient。
     */
    protected function scanDir($dir)
    {
        $changedFiles = [];
        $addedFiles = [];

        foreach (new DirectoryIterator($dir) as $splFileInfo) {
            if ($splFileInfo->isFile()) {
                if (isset($this->extensions[$splFileInfo->getExtension()])) {
                    $path = $splFileInfo->getRealPath();
                    $mtime = $splFileInfo->getMTime();

                    $this->currentMtimes[$path] = $mtime;

                    if (isset($this->lastMtimes[$path])) {

                        if ($this->lastMtimes[$path] !== $mtime) {

                            $hash = hash_file('md5', $path);

                            $this->currentHashes[$path] = $hash;

                            if ($hash !== $this->lastHashes[$path]) {
                                $changedFiles[] = $path;
                            }
                        } else {
                            $this->currentHashes[$path] = $this->lastHashes[$path];
                        }
                    } else {
                        $hash = hash_file('md5', $path);

                        $this->currentHashes[$path] = $hash;

                        $addedFiles[] = $path;
                    }
                }
            } else if ($splFileInfo->isDir() && !$splFileInfo->isDot()) {

                $result = $this->scanDir($splFileInfo->getRealPath());

                $changedFiles = [...$changedFiles, ...$result[0]];
                $addedFiles = [...$addedFiles, ...$result[1]];
            }
        }

        return [$changedFiles, $addedFiles];
    }

    /**
     * 扫描文件
     * @param array $paths 文件路径集合
     * @return array
     * @author Verdient。
     */
    protected function scanFiles(array $paths)
    {
        $changedFiles = [];
        $addedFiles = [];

        foreach ($paths as $path) {
            $mtime = filemtime($path);
            $this->currentMtimes[$path] = $mtime;

            if (isset($this->lastMtimes[$path])) {

                if ($this->lastMtimes[$path] !== $mtime) {

                    $hash = hash_file('md5', $path);

                    $this->currentHashes[$path] = $hash;

                    if ($hash !== $this->lastHashes[$path]) {
                        $changedFiles[] = $path;
                    }
                } else {
                    $this->currentHashes[$path] = $this->lastHashes[$path];
                }
            } else {
                $hash = hash_file('md5', $path);

                $this->currentHashes[$path] = $hash;

                $addedFiles[] = $path;
            }
        }

        return [$changedFiles, $addedFiles];
    }
}
