<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

use Hyperf\Watcher\Command\WatchCommand as CommandWatchCommand;
use Override;
use Swoole\Constant;
use Swoole\Process;

use function Hyperf\Config\config;

/**
 * 监听命令
 *
 * @author Verdient。
 */
class WatchCommand extends CommandWatchCommand
{
    /**
     * @author Verdient。
     */
    #[Override]
    public function handle()
    {
        $pidFile = config('server.settings.' . Constant::OPTION_PID_FILE);

        if (file_exists($pidFile)) {

            $pid = (int) file_get_contents($pidFile);

            if (Process::kill($pid, 0)) {
                if (!$this->confirm('A server is already running. Starting a new one will stop it. Continue?')) {
                    return;
                }

                Process::kill($pid, SIGTERM);
            }
        }

        parent::handle();
    }
}
