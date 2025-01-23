<?php

namespace HotwiredLaravel\Hotreload\Watchers;

use Closure;
use HotwiredLaravel\Hotreload\Contracts\FileWatcher;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SimpleFileWatcher implements FileWatcher
{
    public function __construct(
        private string $path,
        private Closure $onChange,
        private array $files = [],
    ) {}

    public function boot(): void
    {
        $this->files = $this->scan();
    }

    public function tick(): void
    {
        $oldFiles = $this->files;
        $this->files = $this->scan();

        // Nothing changed, skip...
        if (md5(json_encode($oldFiles)) === md5(json_encode($this->files))) {
            return;
        }

        $changes = [];

        foreach ($this->files as $file => $mtime) {
            if (! isset($oldFiles[$file])) {
                // Added files...
                $changes[] = $file;
            } elseif ($oldFiles[$file] !== $mtime) {
                // Modified files...
                $changes[] = $file;
            }
        }

        foreach ($oldFiles as $file => $mtime) {
            // Removed files...
            if (! isset($this->files[$file])) {
                $changes[] = $file;
            }
        }

        foreach ($changes as $change) {
            call_user_func($this->onChange, $change);
        }
    }

    public function stop(): void
    {
        //
    }

    protected function scan(): array
    {
        if (! is_dir($this->path)) {
            return [$this->path => file_exists($this->path) ? filemtime($this->path) : 0];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path)) as $item) {
            if ($item->isDir()) {
                continue;
            }

            if (str_starts_with($item->getFilename(), '.')) {
                continue;
            }

            $files[$item->getPathname()] = $item->getMTime();
        }

        return $files;
    }
}
