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
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        \Hyperf\Watcher\Process::class => __DIR__ . '/class_map/Process.php',
                    ]
                ],
            ],
        ];
    }
}
