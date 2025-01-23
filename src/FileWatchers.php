<?php

namespace HotwiredLaravel\Hotreload;

use HotwiredLaravel\Hotreload\Contracts\FileWatcher;

class FileWatchers implements FileWatcher
{
    /**
     * @param  array<int,FileWatcher>  $watchers
     */
    public function __construct(private array $watchers) {}

    public function boot(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->boot();
        }
    }

    public function tick(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->tick();
        }
    }

    public function stop(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->stop();
        }
    }

    public function all(): array
    {
        return $this->watchers;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
