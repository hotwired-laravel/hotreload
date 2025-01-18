<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\BrowserTestCase;

use function Orchestra\Testbench\workbench_path;

class MorphHtmlTest extends BrowserTestCase
{
    #[Test]
    public function reloads_html(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/?reload_method=morph')
                ->assertSee('REPLACE_HTML');

            $visit->pause(300);

            $this->editFile(workbench_path('resources', 'views', 'hello.blade.php'), 'REPLACE_HTML', 'Amazing!');

            $visit->waitForText('Amazing!');
        });
    }
}
