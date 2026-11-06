<?php

namespace Tests\Browser;

use HotwiredLaravel\Hotreload\Hotreload;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Browser;
use Tests\BrowserTestCase;

use function Orchestra\Testbench\package_path;
use function Orchestra\Testbench\workbench_path;

class DaemonTest extends BrowserTestCase
{
    private static ?Process $daemon = null;

    #[Override]
    public static function tearDownAfterClass(): void
    {
        static::$daemon?->stop();
        static::$daemon = null;

        parent::tearDownAfterClass();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Testbench Dusk serves the app with `php -S`, so the `serve` hook doesn't fire. We start the daemon ourselves...
        if (static::$daemon === null) {
            @unlink(Hotreload::hotFile());

            static::$daemon = new Process(
                [PHP_BINARY, package_path('vendor', 'bin', 'testbench'), 'hotreload:serve', '--port=0'],
                package_path(),
                ['HOTRELOAD_ENABLED' => 'true'],
            );

            static::$daemon->start();

            $deadline = microtime(true) + 10;

            while (! Hotreload::runningDaemon()) {
                if (microtime(true) > $deadline) {
                    $this->fail('Daemon did not start: ' . static::$daemon->getErrorOutput() . static::$daemon->getOutput());
                }

                usleep(50_000);
            }
        }
    }

    #[Test]
    public function reloads_html_through_the_daemon(): void
    {
        $port = Hotreload::runningDaemon()['port'];

        $this->browse(function (Browser $browser) use ($port) {
            $visit = $browser->visit('/?reload_method=morph');

            $this->assertSame(
                "http://127.0.0.1:{$port}",
                $visit->script('return document.body.dataset.hotwireHotreloadUrl')[0],
            );

            $visit->assertSee('REPLACE_HTML');

            $this->editFile(workbench_path('resources', 'views', 'hello.blade.php'), 'REPLACE_HTML', 'Amazing!');

            $visit->waitForText('Amazing!');
        });
    }

    #[Test]
    public function falls_back_to_the_in_process_route_when_daemon_is_unreachable(): void
    {
        static::$daemon->stop();
        static::$daemon = null;

        // A hot file pointing to a dead port, owned by a live process (us)...
        file_put_contents(Hotreload::hotFile(), json_encode(['port' => 1, 'pid' => getmypid()]));

        try {
            $this->browse(function (Browser $browser) {
                $visit = $browser->visit('/?reload_method=morph');

                $this->assertSame(
                    '/hotwired-laravel-hotreload/sse',
                    $visit->script('return document.body.dataset.hotwireHotreloadUrl')[0],
                );

                $this->editFile(workbench_path('resources', 'views', 'hello.blade.php'), 'REPLACE_HTML', 'Fallback!');

                $visit->waitForText('Fallback!');
            });
        } finally {
            @unlink(Hotreload::hotFile());
        }
    }
}
