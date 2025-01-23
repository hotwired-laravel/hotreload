<?php

namespace Tests\Unit\Watchers;

use Closure;
use HotwiredLaravel\Hotreload\Contracts\FileWatcher;
use HotwiredLaravel\Hotreload\Watchers\SimpleFileWatcher;
use Tests\UnitTestCase;

class SimpleFileWatcherTest extends UnitTestCase
{
    use FileWatcherTests;

    protected function watcher(string $path, Closure $onChange): FileWatcher
    {
        return new SimpleFileWatcher($path, $onChange);
    }
}
