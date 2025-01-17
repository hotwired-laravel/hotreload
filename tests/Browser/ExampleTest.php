<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\BrowserTestCase;

class ExampleTest extends BrowserTestCase
{
    #[Test]
    public function works(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Hotwired Laravel Hotreload App');
        });
    }
}
