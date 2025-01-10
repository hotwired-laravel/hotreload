<?php

namespace HotwiredLaravel\Hotreload\Events;

class ReloadHtml implements HotreloadEvent
{
    public function __construct(
        public string $path,
    ) {}

    public function eventName(): string
    {
        return 'reload_html';
    }

    public function eventData(): array
    {
        return ['path' => $this->path];
    }
}
