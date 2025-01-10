<?php

namespace HotwiredLaravel\Hotreload\Contracts;

interface FileWatcher
{
    public function boot(): void;

    public function tick(): void;
}
