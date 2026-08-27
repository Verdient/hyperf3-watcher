<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

use Hyperf\Watcher\Command\WatchCommand as CommandWatchCommand;
use Hyperf\Watcher\Watcher as WatcherWatcher;
use Verdient\Hyperf3\Watcher\WatchCommand;
use Verdient\Hyperf3\Watcher\Watcher;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                CommandWatchCommand::class => WatchCommand::class,
                WatcherWatcher::class => Watcher::class
            ]
        ];
    }
}
