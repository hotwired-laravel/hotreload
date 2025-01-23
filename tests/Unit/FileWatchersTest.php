<?php

namespace Tests\Unit;

use HotwiredLaravel\Hotreload\Contracts\FileWatcher;
use HotwiredLaravel\Hotreload\FileWatchers;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class FileWatchersTest extends UnitTestCase
{
    #[Test]
    public function multiple_watchers(): void
    {
        $boots = 0;
        $ticks = 0;

        $watchers = new FileWatchers([
            new DummyFileWatcher($boots, $ticks),
            new DummyFileWatcher($boots, $ticks),
        ]);

        $this->assertEquals(0, $ticks);

        $watchers->tick();

        $this->assertEquals(2, $ticks);

        $this->assertEquals(0, $boots);

        $watchers->boot();

        $this->assertEquals(2, $boots);
    }
}

class DummyFileWatcher implements FileWatcher
{
    public function __construct(
        private int &$boots,
        private int &$ticks
    ) {}

    public function boot(): void
    {
        $this->boots++;
    }

    public function tick(): void
    {
        $this->ticks++;
    }

    public function stop(): void
    {
        //
    }
}
