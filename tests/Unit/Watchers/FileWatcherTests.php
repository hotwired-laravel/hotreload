<?php

namespace Tests\Unit\Watchers;

use Closure;
use HotwiredLaravel\Hotreload\Contracts\FileWatcher;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;

trait FileWatcherTests
{
    #[Test]
    public function watches_file_changed(): void
    {
        Storage::fake('local')->put($path = 'test.txt', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path($path), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Sleep::for(1)->second();

        Storage::disk('local')->put($path, 'changed');

        $watcher->tick();

        $this->assertTrue($called, 'Callback wasnt called.');
    }

    #[Test]
    public function watches_file_removed(): void
    {
        Storage::fake('local')->put($path = 'test.txt', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path($path), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Sleep::for(1)->second();

        Storage::disk('local')->delete($path);

        $watcher->tick();

        $this->assertTrue($called, 'Callback wasnt called.');
    }

    #[Test]
    public function watches_files_added_to_a_directory(): void
    {
        Storage::fake('local');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Storage::disk('local')->put('test.txt', 'hello');

        $watcher->tick();

        $this->assertTrue($called, 'Callback wasnt called.');
    }

    #[Test]
    public function watches_files_changed_in_a_directory(): void
    {
        Storage::fake('local')->put('test.txt', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Sleep::for(1)->second();

        Storage::disk('local')->put('test.txt', 'world');

        $watcher->tick();

        $this->assertTrue($called, 'Callback wasnt called.');
    }

    #[Test]
    public function watches_for_files_removed_in_a_directory(): void
    {
        Storage::fake('local')->put('test.txt', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Sleep::for(1)->second();

        Storage::disk('local')->delete('test.txt');

        $watcher->tick();

        $this->assertTrue($called, 'Callback wasnt called.');
    }

    #[Test]
    public function watches_for_files_recursively(): void
    {
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('testing/deep/');
        Storage::disk('local')->put('testing/deep/test.txt', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Sleep::for(1)->second();

        Storage::disk('local')->put('testing/deep/test.txt', 'world');

        $watcher->tick();

        $this->assertTrue($called, 'Callback wasnt called.');
    }

    #[Test]
    public function ignores_hidden_files_in_directory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('testing/deep/');
        Storage::disk('local')->put('testing/deep/.test', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();

        Sleep::for(1)->second();

        Storage::disk('local')->put('testing/deep/.test', 'world');

        $watcher->tick();

        $this->assertFalse($called, 'Callback should not be called.');
    }

    #[Test]
    public function can_stop_watching(): void
    {
        Storage::fake('local');

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () {});

        $watcher->boot();

        $watcher->tick();
        $watcher->stop();
        $watcher->stop();

        $this->assertTrue(true);
    }

    #[Test]
    public function nothing_changed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('testing/deep/');
        Storage::disk('local')->put('testing/deep/.test', 'hello');

        $called = false;

        $watcher = $this->watcher(Storage::disk('local')->path('/'), function () use (&$called) {
            $called = true;
        });

        $watcher->boot();
        $watcher->tick();

        $this->assertFalse($called, 'Callback should not be called when nothing changed.');
    }

    abstract protected function watcher(string $path, Closure $onChange): FileWatcher;
}
