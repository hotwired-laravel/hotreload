<?php

namespace Tests\Unit\Http\Middleware;

use HotwiredLaravel\Hotreload\Hotreload;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class HotreloadMiddlewareTest extends UnitTestCase
{
    /** @var resource|null */
    private $lock = null;

    #[Override]
    protected function tearDown(): void
    {
        if ($this->lock) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }

        @unlink(Hotreload::hotFile());

        parent::tearDown();
    }

    #[Test]
    public function doesnt_inject_daemon_url_when_daemon_isnt_running(): void
    {
        @unlink(Hotreload::hotFile());

        $this->get('/')->assertOk()->assertDontSee('hotwire-hotreload:url', false);
    }

    #[Test]
    public function injects_daemon_url_when_daemon_is_running(): void
    {
        $this->fakeRunningDaemon(4321);

        $this->get('http://myapp.test/')->assertSee(<<<'HTML'
        <meta name="hotwire-hotreload:url" content="http://myapp.test:4321">
        HTML, false);
    }

    #[Test]
    public function daemon_url_can_be_configured(): void
    {
        config()->set('hotreload.url', 'http://localhost:9999');

        $this->fakeRunningDaemon(4321);

        $this->get('/')->assertSee(<<<'HTML'
        <meta name="hotwire-hotreload:url" content="http://localhost:9999">
        HTML, false);
    }

    #[Test]
    public function configured_daemon_url_is_injected_even_when_the_daemon_isnt_visible(): void
    {
        // Like when the daemon runs in another container...
        @unlink(Hotreload::hotFile());

        config()->set('hotreload.url', 'http://localhost:9999');

        $this->get('/')->assertSee(<<<'HTML'
        <meta name="hotwire-hotreload:url" content="http://localhost:9999">
        HTML, false);
    }

    #[Test]
    public function ignores_stale_hot_files(): void
    {
        // A hot file left behind by a daemon that died without cleaning up (nobody holds the lock)...
        file_put_contents(Hotreload::hotFile(), json_encode(['port' => 4321, 'pid' => getmypid()]));

        $this->get('/')->assertOk()->assertDontSee('hotwire-hotreload:url', false);
    }

    #[Test]
    public function exposes_the_daemon_url_for_reconnecting_clients(): void
    {
        @unlink(Hotreload::hotFile());

        $this->getJson('http://myapp.test/hotwired-laravel-hotreload/daemon')->assertOk()->assertExactJson(['url' => null]);

        $this->fakeRunningDaemon(4321);

        $this->getJson('http://myapp.test/hotwired-laravel-hotreload/daemon')
            ->assertOk()
            ->assertExactJson(['url' => 'http://myapp.test:4321'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function injects_scripts(): void
    {
        $this->get('/')->assertSee(<<<'HTML'
        <script src="/hotwired-laravel-hotreload/hotreload.js?v=
        HTML, false);
    }

    #[Test]
    public function doesnt_inject_on_errors(): void
    {
        $this->get('errors')->assertNotFound()->assertDontSee(<<<'HTML'
        <script src="/hotwired-laravel-hotreload/hotreload.js?v=
        HTML, false);
    }

    #[Test]
    public function doesnt_inject_on_redirects(): void
    {
        $this->get('redirect')->assertRedirect('/')->assertDontSee(<<<'HTML'
        <script src="/hotwired-laravel-hotreload/hotreload.js?v=
        HTML, false);
    }

    /**
     * Holds the daemon's lock like a running daemon would.
     */
    private function fakeRunningDaemon(int $port): void
    {
        @mkdir(dirname(Hotreload::lockFile()), recursive: true);

        $this->lock = fopen(Hotreload::lockFile(), 'c');
        flock($this->lock, LOCK_EX);

        file_put_contents(Hotreload::hotFile(), json_encode(['port' => $port, 'pid' => getmypid()]));
    }
}
