<?php

namespace HotwiredLaravel\Hotreload\Events;

class ReloadStimulus implements HotreloadEvent
{
    public function __construct(
        public string $path,
    ) {}

    public function eventName(): string
    {
        return 'reload_stimulus';
    }

    public function eventData(): array
    {
        return ['path' => $this->path];
    }
}
