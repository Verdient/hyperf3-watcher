<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

/**
 * 文件变化事件
 *
 * @author Verdient。
 */
class FileChangedEvent
{
    /**
     * @param string $path 变化的文件路径
     *
     * @author Verdient。
     */
    public function __construct(public readonly string $path) {}
}
