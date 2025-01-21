<?php

namespace HotwiredLaravel\Hotreload\Exceptions;

use RuntimeException;

class HotreloadException extends RuntimeException
{
    public static function inotifyExtensionMissing(): static
    {
        return new static('The `inotify` extension is missing or not loaded. Install it or switch to the simple watcher.');
    }
}

