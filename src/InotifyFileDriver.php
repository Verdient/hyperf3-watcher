<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

use ErrorException;
use FilesystemIterator;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Engine\Channel;
use Hyperf\Watcher\Driver\DriverInterface;
use Hyperf\Watcher\Option;
use Override;
use RuntimeException;
use Swoole\Event;
use Verdient\Hyperf3\Event\Event as Hyperf3Event;

/**
 * Inotify驱动
 *
 * @author Verdient。
 */
class InotifyFileDriver implements DriverInterface
{
    /**
     * @var ?resource inotify 资源句柄
     *
     * @author Verdient。
     */
    protected $inotifyInstance = null;

    /**
     * @var array<int,string> 监听描述符 => 路径 映射
     *
     * @author Verdient。
     */
    protected array $pathMap = [];

    /**
     * @var array<string,int> 路径 => 监听描述符 映射
     *
     * @author Verdient。
     */
    protected array $descriptorMap = [];

    /**
     * 最后一次的事件
     *
     * @author Verdient。
     */
    protected array $lastEvents = [];

    /**
     * 最后一次的事件触发的时间
     *
     * @author Verdient。
     */
    protected float $lastEventAt = 0;

    /**
     * @var array<string,string> 上次的哈希值集合
     *
     * @author Verdient。
     */
    protected array $lastHashes = [];

    /**
     * @var array<string,true> 监控的文件夹
     *
     * @author Verdient。
     */
    protected array $dirs = [];

    /**
     * @var array<string,true> 监控的文件
     *
     * @author Verdient。
     */
    protected array $files = [];

    /**
     * 监控的事件掩码
     *
     * @author Verdient。
     */
    protected int $eventMask = IN_CREATE | // 创建文件/目录
        IN_DELETE | // 删除文件/目录
        IN_MODIFY | // 文件内容被修改
        IN_DELETE_SELF | // 被监听的文件/目录被删除
        IN_MOVED_FROM | // 文件/目录被移动离开
        IN_MOVED_TO | // 文件/目录被移动进来
        IN_ATTRIB | // 元数据变化（权限、时间戳等）
        IN_IGNORED;

    /**
     * @param Option $option 选项
     * @param StdoutLoggerInterface $logger 日志
     *
     * @author Verdient。
     */
    public function __construct(protected Option $option, protected StdoutLoggerInterface $logger)
    {
        if (!extension_loaded('inotify')) {
            throw new ErrorException('Please install the inotify extension first.');
        }

        $this->inotifyInstance = inotify_init();

        if ($this->inotifyInstance === false) {
            throw new RuntimeException('Unable to initialize inotify instance.');
        }

        stream_set_blocking($this->inotifyInstance, false);

        foreach ([...$this->option->getWatchDir(), ...$this->option->getWatchFile()] as $path) {
            if (!str_starts_with($path, '/')) {
                $path = BASE_PATH . '/' . $path;
            }

            if (!file_exists($path)) {
                continue;
            }

            if (is_dir($path)) {
                $this->addDirWatch($path);
            } else {
                $this->addFileWatch($path);
            }
        }
    }

    /**
     * @author Verdient。
     */
    #[Override]
    public function watch(Channel $channel): void
    {
        Event::add($this->inotifyInstance, fn() => $this->handleReadable($channel));
    }

    /**
     * 添加文件监听
     *
     * @param string $path 路径
     *
     * @author Verdient。
     */
    protected function addFileWatch(string $path): void
    {
        if (isset($this->files[$path])) {
            return;
        }

        $this->files[$path] = true;

        $wd = inotify_add_watch($this->inotifyInstance, $path, $this->eventMask);

        if ($wd === false) {
            throw new RuntimeException("Unable to add a listener to the file: {$path}.");
        }

        $this->pathMap[$wd] = $path;
        $this->descriptorMap[$path] = $wd;

        $this->lastHashes[$path] = md5_file($path);
    }

    /**
     * 添加文件夹监听
     *
     * @param string $path 路径
     *
     * @author Verdient。
     */
    protected function addDirWatch(string $path): void
    {
        if (isset($this->dirs[$path])) {
            return;
        }

        $this->dirs[$path] = true;

        $wd = inotify_add_watch($this->inotifyInstance, $path, $this->eventMask);

        if ($wd === false) {
            throw new RuntimeException("Unable to add a listener to the directory: {$path}.");
        }

        $this->pathMap[$wd] = $path;
        $this->descriptorMap[$path] = $wd;

        foreach (new FilesystemIterator($path) as $splFileInfo) {
            if ($splFileInfo->isDir()) {
                $this->addDirWatch($splFileInfo->getPathname());
            } else {
                $this->addFileWatch($splFileInfo->getPathname());
            }
        }
    }

    /**
     * 移除文件监听
     *
     * @param string $path 路径
     *
     * @author Verdient。
     */
    protected function removeFileWatch(string $path): void
    {
        if (!isset($this->files[$path])) {
            return;
        }

        if (!isset($this->descriptorMap[$path])) {
            return;
        }

        $wd = $this->descriptorMap[$path];

        @inotify_rm_watch($this->inotifyInstance, $wd);

        unset($this->files[$path], $this->descriptorMap[$path], $this->pathMap[$wd], $this->lastHashes[$path]);
    }

    /**
     * 移除文件夹监听
     *
     * @param string $path 路径
     *
     * @author Verdient。
     */
    protected function removeDirWatch(string $path): void
    {
        if (!isset($this->dirs[$path])) {
            return;
        }

        if (!isset($this->descriptorMap[$path])) {
            return;
        }

        $wd = $this->descriptorMap[$path];

        @inotify_rm_watch($this->inotifyInstance, $wd);

        unset($this->dirs[$path], $this->descriptorMap[$path], $this->pathMap[$wd]);

        foreach ($this->collectRemovedFiles($path) as $path2) {
            $this->removeFileWatch($path2);
        }

        foreach ($this->collectRemovedDirs($path) as $path2) {
            $this->removeDirWatch($path2);
        }
    }

    /**
     * 处理可读事件
     *
     * @param Channel $channel 通道
     *
     * @author Verdient。
     */
    protected function handleReadable(Channel $channel): void
    {
        $events = inotify_read($this->inotifyInstance);

        if (empty($events)) {
            return;
        }

        if (
            $events === $this->lastEvents
            && (microtime(true) - $this->lastEventAt) < 0.1
        ) {
            return;
        }

        $this->lastEventAt = microtime(true);
        $this->lastEvents = $events;

        if (
            count($events) === 2
            && !empty($events[0]['name'])
            && empty($events[1]['name'])
        ) {
            array_pop($events);
        }

        foreach ($events as $event) {
            $this->handleEvent($event, $channel);
        }
    }

    /**
     * 处理单个事件
     *
     * @param array $event 事件
     * @param Channel $channel 通道
     *
     * @author Verdient。
     */
    protected function handleEvent(array $event, Channel $channel): void
    {
        $wd = $event['wd'];
        $mask = $event['mask'];
        $name = $event['name'] ?? '';

        $basePath = $this->pathMap[$wd] ?? null;

        if ($basePath === null) {
            return;
        }

        $fullPath = $name !== '' ? $basePath . '/' . $name : $basePath;

        $isDir = ($mask & IN_ISDIR) === IN_ISDIR;

        $hasDelete = false;

        $shouldRestart = false;

        if ($mask & IN_CREATE) {
            if ($isDir) {
                $this->addDirWatch($fullPath);
                foreach ($this->collectAddedFiles($fullPath) as $filePath) {
                    $this->dispatchEvent($filePath);
                }
            } else {
                $this->addFileWatch($fullPath);
                $this->dispatchEvent($fullPath);
            }

            $shouldRestart = true;
        }

        if ($mask & IN_DELETE) {
            if ($isDir) {
                $removedFiles = $this->collectRemovedFiles($fullPath);
                $this->removeDirWatch($fullPath);
                foreach ($removedFiles as $removedFile) {
                    $this->dispatchEvent($removedFile);
                }
            } else {
                $this->removeFileWatch($fullPath);
                $this->dispatchEvent($fullPath);
            }

            $hasDelete = true;
        }

        if ($mask & IN_MODIFY) {
            $hash = md5_file($fullPath);

            $shouldRestart = !isset($this->lastHashes[$fullPath]) || $this->lastHashes[$fullPath] !== $hash;

            if ($shouldRestart) {
                $this->lastHashes[$fullPath] = $hash;
                $this->dispatchEvent($fullPath);
            }
        }

        if ($mask & IN_MOVED_FROM) {
            if ($isDir) {
                $removedFiles = $this->collectRemovedFiles($fullPath);
                $this->removeDirWatch($fullPath);
                foreach ($removedFiles as $removedFile) {
                    $this->dispatchEvent($removedFile);
                }
            } else {
                $this->removeFileWatch($fullPath);
                $this->dispatchEvent($fullPath);
            }

            $hasDelete = true;
        }

        if ($mask & IN_MOVED_TO) {
            if ($isDir) {
                $this->addDirWatch($fullPath);
                foreach ($this->collectAddedFiles($fullPath) as $filePath) {
                    $this->dispatchEvent($filePath);
                }
            } else {
                $this->addFileWatch($fullPath);
                $this->dispatchEvent($fullPath);
            }

            $hasDelete = true;
        }

        if ($mask & IN_DELETE_SELF) {
            if ($isDir) {
                $removedFiles = $this->collectRemovedFiles($fullPath);
                $this->removeDirWatch($fullPath);
                foreach ($removedFiles as $removedFile) {
                    $this->dispatchEvent($removedFile);
                }
            } else {
                $this->removeFileWatch($fullPath);
                $this->dispatchEvent($fullPath);
            }

            $hasDelete = true;
        }

        if ($mask & IN_IGNORED) {
            if (isset($this->pathMap[$wd])) {
                $path = $this->pathMap[$wd];
                if ($isDir) {
                    unset($this->dirs[$path], $this->descriptorMap[$path], $this->pathMap[$wd]);
                } else {
                    unset($this->files[$path], $this->descriptorMap[$path], $this->pathMap[$wd], $this->lastHashes[$path]);
                }
            }
        }

        if ($hasDelete) {
            $this->logger->warning('Delete files must be restarted manually to take effect.');
        }

        if ($shouldRestart) {
            $channel->push($fullPath);
        }
    }

    /**
     * 收集新增的文件
     *
     * @param string $path 路径
     *
     * @return string[]
     * @author Verdient。
     */
    protected function collectAddedFiles(string $path): array
    {
        $result = [];

        foreach (new FilesystemIterator($path) as $splFileInfo) {
            if ($splFileInfo->isDir()) {
                foreach ($this->collectAddedFiles($splFileInfo->getPathname()) as $file) {
                    $result[] = $file;
                }
            } else {
                $result[] = $splFileInfo->getPathname();
            }
        }

        return $result;
    }

    /**
     * 收集删除的文件夹
     *
     * @param string $path 路径
     *
     * @return string[]
     * @author Verdient。
     */
    protected function collectRemovedDirs(string $path): array
    {
        $result = [];

        $prefix = str_ends_with($path, '/') ? $path : ($path . '/');

        $watchedPaths = array_keys($this->dirs);

        foreach ($watchedPaths as $watchedPath) {
            if (str_starts_with($watchedPath, $prefix)) {
                $result[] = $watchedPath;
            }
        }

        return $result;
    }

    /**
     * 收集删除的文件
     *
     * @param string $path 路径
     *
     * @return string[]
     * @author Verdient。
     */
    protected function collectRemovedFiles(string $path): array
    {
        $result = [];

        $prefix = str_ends_with($path, '/') ? $path : ($path . '/');

        $watchedPaths = array_keys($this->files);

        foreach ($watchedPaths as $watchedPath) {
            if (str_starts_with($watchedPath, $prefix)) {
                $result[] = $watchedPath;
            }
        }

        return $result;
    }

    /**
     * 触发事件
     *
     * @param string $path 路径
     *
     * @author Verdient。
     */
    protected function dispatchEvent(string $path): void
    {
        Hyperf3Event::dispatch(new FileChangedEvent($path));
    }
}
