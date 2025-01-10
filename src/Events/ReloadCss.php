<?php

namespace HotwiredLaravel\Hotreload\Events;

class ReloadCss implements HotreloadEvent
{
    public function __construct(
        public string $path,
    ) {}

    public function eventName(): string
    {
        return 'reload_css';
    }

    public function eventData(): array
    {
        return ['path' => $this->path];
    }
}
