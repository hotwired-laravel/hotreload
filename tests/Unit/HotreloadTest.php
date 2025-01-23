<?php

namespace Tests\Unit;

use HotwiredLaravel\Hotreload\Hotreload;
use HotwiredLaravel\Hotreload\Watchers\InotifyFileWatcher;
use HotwiredLaravel\Hotreload\Watchers\SimpleFileWatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class HotreloadTest extends UnitTestCase
{
    #[Test]
    public function can_configure_the_watcher(): void
    {
        Hotreload::withInotifyWatcher();

        $this->assertInstanceOf(InotifyFileWatcher::class, collect(Hotreload::watchers()->all())->first());

        Hotreload::withSimpleWatcher();

        $this->assertInstanceOf(SimpleFileWatcher::class, collect(Hotreload::watchers()->all())->first());
    }
}
