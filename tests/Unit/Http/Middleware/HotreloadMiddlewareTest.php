<?php

namespace Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class HotreloadMiddlewareTest extends UnitTestCase
{
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
