<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

use Hyperf\Watcher\Command\WatchCommand as CommandWatchCommand;
use Verdient\Hyperf3\Watcher\WatchCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                CommandWatchCommand::class => WatchCommand::class
            ]
        ];
    }
}
