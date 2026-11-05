<?php

namespace Tests\Unit\Http\Middleware;

use HotwiredLaravel\Hotreload\Hotreload;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class HotreloadMiddlewareTest extends UnitTestCase
{
    #[Override]
    protected function tearDown(): void
    {
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
        file_put_contents(Hotreload::hotFile(), json_encode(['port' => 4321, 'pid' => getmypid()]));

        $this->get('http://myapp.test/')->assertSee(<<<'HTML'
        <meta name="hotwire-hotreload:url" content="http://myapp.test:4321">
        HTML, false);
    }

    #[Test]
    public function daemon_url_can_be_configured(): void
    {
        config()->set('hotreload.url', 'http://localhost:9999');

        file_put_contents(Hotreload::hotFile(), json_encode(['port' => 4321, 'pid' => getmypid()]));

        $this->get('/')->assertSee(<<<'HTML'
        <meta name="hotwire-hotreload:url" content="http://localhost:9999">
        HTML, false);
    }

    #[Test]
    public function ignores_stale_hot_files(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires the posix extension.');
        }

        file_put_contents(Hotreload::hotFile(), json_encode(['port' => 4321, 'pid' => 999999999]));

        $this->get('/')->assertOk()->assertDontSee('hotwire-hotreload:url', false);
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
}
