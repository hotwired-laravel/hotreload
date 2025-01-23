<?php

namespace Tests\Unit\Watchers;

use Closure;
use HotwiredLaravel\Hotreload\Contracts\FileWatcher;
use HotwiredLaravel\Hotreload\Watchers\InotifyFileWatcher;
use Tests\UnitTestCase;

class InotifyFileWatcherTest extends UnitTestCase
{
    use FileWatcherTests;

    protected function watcher(string $path, Closure $onChange): FileWatcher
    {
        return new InotifyFileWatcher($path, $onChange);
    }
}
