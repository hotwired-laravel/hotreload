<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\BrowserTestCase;

use function Orchestra\Testbench\workbench_path;

class ReplaceHtmlTest extends BrowserTestCase
{
    #[Test]
    public function reloads_html(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->assertSee('REPLACE_HTML');

            $visit->pause(200);

            $this->editFile(workbench_path('resources', 'views', 'hello.blade.php'), 'REPLACE_HTML', 'Amazing!');

            $visit->waitForText('Amazing!');
        });
    }

    #[Test]
    public function reloads_when_recursive_file_changes(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->assertSee('REPLACE_NESTED');

            $visit->pause(300);

            $this->editFile(workbench_path('resources', 'views', 'components', 'test.blade.php'), 'REPLACE_NESTED', 'Awesome!');

            $visit->waitForText('Awesome!');
        });
    }
}
