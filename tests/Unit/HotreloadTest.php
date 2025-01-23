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

    #[Test]
    public function can_add_paths(): void
    {
        $this->assertNotContains(__DIR__, Hotreload::htmlPaths());

        Hotreload::addHtmlPath(__DIR__);

        $this->assertContains(__DIR__, Hotreload::htmlPaths());

        $this->assertNotContains(__DIR__, Hotreload::stimulusPaths());

        Hotreload::addStimulusPath(__DIR__);

        $this->assertContains(__DIR__, Hotreload::stimulusPaths());

        $this->assertNotContains(__DIR__, Hotreload::cssPaths());

        Hotreload::addCssPath(__DIR__);

        $this->assertContains(__DIR__, Hotreload::cssPaths());
    }

    #[Test]
    public function resets_paths(): void
    {
        $before = Hotreload::htmlPaths();

        Hotreload::addHtmlPath(__DIR__);
        Hotreload::resetPaths();

        $this->assertEquals($before, Hotreload::htmlPaths());
    }
}
