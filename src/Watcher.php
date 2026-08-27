<?php

declare(strict_types=1);

namespace Verdient\Hyperf3\Watcher;

use Hyperf\Engine\Channel;
use Hyperf\Watcher\Watcher as HyperfWatcher;
use Swoole\Coroutine;

/**
 * 监听命令
 *
 * @author Verdient。
 */
class Watcher extends HyperfWatcher
{
    public function run()
    {
        $this->dumpAutoload();
        $this->restart(true);

        $channel = new Channel(999);
        Coroutine::create(function () use ($channel) {
            $this->driver->watch($channel);
        });

        $result = [];
        while (true) {
            $file = $channel->pop(0.001);
            if ($file === false) {
                if (count($result) > 0) {
                    $result = [];
                    $this->restart(false);
                }
            } else {
                $ret = exec(sprintf('%s %s/collector-reload.php %s', $this->option->getBin(), dirname(__DIR__), $file));
                if (isset($ret['code']) && $ret['code'] === 0) {
                    $this->output->writeln('Class reload success.');
                } else {
                    $this->output->writeln('Class reload failed.');
                    $this->output->writeln($ret['output'] ?? '');
                }
                $result[] = $file;
            }
        }
    }
}
