<?php

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Test;
use Tests\Browser;
use Tests\BrowserTestCase;

use function Orchestra\Testbench\workbench_path;

class ReplaceHtmlTest extends BrowserTestCase
{
    #[Test]
    public function reloads_html(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->waitForHotreload()
                ->assertSee('REPLACE_HTML');

            $this->editFile(workbench_path('resources', 'views', 'hello.blade.php'), 'REPLACE_HTML', 'Amazing!');

            $visit->waitForText('Amazing!');
        });
    }

    #[Test]
    public function reloads_when_recursive_file_changes(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->waitForHotreload()
                ->assertSee('REPLACE_NESTED');

            $this->editFile(workbench_path('resources', 'views', 'components', 'test.blade.php'), 'REPLACE_NESTED', 'Awesome!');

            $visit->waitForText('Awesome!');
        });
    }
}
