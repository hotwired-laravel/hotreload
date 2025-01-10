<?php

namespace HotwiredLaravel\Hotreload\Events;

interface HotreloadEvent
{
    public function eventName(): string;

    public function eventData(): array;
}

