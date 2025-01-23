<?php

namespace HotwiredLaravel\Hotreload\Watchers;

use Closure;
use HotwiredLaravel\Hotreload\Contracts\FileWatcher as ContractsFileWatcher;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InotifyFileWatcher implements ContractsFileWatcher
{
    private $fd;
    private $watchers = [];
    private $stopped = false;

    public function __construct(
        private string $path,
        private Closure $onChange,
        private array $files = [],
    ) {}

    public function boot(): void
    {
        $this->fd = inotify_init();

        $this->startWatching();
        $this->ensureItDoesntBlock();
    }

    public function tick(): void
    {
        $events = inotify_read($this->fd);

        if ($events === false) {
            return;
        }

        if (! is_dir($this->path)) {
            $events = count($events) > 0 ? [$this->path => $events] : [];
        } else {
            $events = collect($events)
                ->lazy()
                ->filter(fn ($event) => ! empty($event['name']))
                ->filter(fn ($event) => ! is_numeric($event['name']))
                ->filter(fn ($event) => ! str_starts_with($event['name'], '.'))
                ->filter(fn ($event) => ! str_ends_with($event['name'], '~'))
                ->groupBy('name')
                ->all();
        }

        foreach ($events as $file => $events) {
            call_user_func($this->onChange, rtrim($this->path, '/') . "/{$file}");
        }
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        foreach ($this->watchers as $wd) {
            @inotify_rm_watch($this->fd, $wd);
        }

        $this->fd && fclose($this->fd);

        $this->stopped = true;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function ensureItDoesntBlock(): void
    {
        $read = [$this->fd];
        $write = null;
        $except = null;
        stream_select($read, $write, $except, 0);

        stream_set_blocking($this->fd, false);
    }

    private function startWatching(): void
    {
        $this->watchers[] = inotify_add_watch($this->fd, $this->path, IN_MODIFY | IN_CREATE | IN_DELETE | IN_ATTRIB | IN_MOVE | IN_DELETE_SELF);

        if (! is_dir($this->path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        // Add watch for each subdirectory...
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $this->watchers[] = inotify_add_watch($this->fd, $item->getPathname(), IN_MODIFY | IN_CREATE | IN_DELETE | IN_ATTRIB | IN_MOVE);
            }
        }
    }
}
