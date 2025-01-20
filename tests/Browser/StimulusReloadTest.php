<?php

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Test;
use Tests\Browser;
use Tests\BrowserTestCase;

use function Orchestra\Testbench\workbench_path;

class StimulusReloadTest extends BrowserTestCase
{
    #[Test]
    public function reloads_stimulus(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->waitForTextIn('#replace', '__REPLACED_STIMULUS__')
                ->assertSee('__REPLACED_STIMULUS__');

            $this->editFile(workbench_path('resources', 'assets', 'js', 'controllers', 'dummy_controller.js'), '__REPLACED_STIMULUS__', '__REPLACED_STIMULUS_V2__');

            $visit->waitUntilMissingText('__REPLACED_STIMULUS__');
            $visit->assertSee('__REPLACED_STIMULUS_V2__');
        });
    }

    #[Test]
    public function detects_new_controllers(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->waitForTextIn('#replace', '__REPLACED_STIMULUS__')
                ->assertSee('__REPLACED_STIMULUS__');

            $this->editFile(workbench_path('resources', 'views', 'hello.blade.php'), 'REPLACE_CONTROLLER', 'other-dummy');
            $this->addFile(workbench_path('resources', 'assets', 'js', 'controllers', 'other_dummy_controller.js'), <<<'JS'
            import { Controller } from "@hotwired/stimulus"

            export default class extends Controller {
                connect() {
                    this.element.querySelector("#other_replace").textContent = "__OTHER_REPLACED__"
                }
            }
            JS);

            $visit->waitForText('__OTHER_REPLACED__');
            $visit->assertSee('__OTHER_REPLACED__');
        });
    }

    #[Test]
    public function unloads_removed_controllers(): void
    {
        $this->browse(function (Browser $browser) {
            $visit = $browser->visit('/')
                ->waitForText('__REPLACED_STIMULUS__');

            $this->assertNotNull($visit->element('[data-dummy-version]'));

            $this->deleteFile(workbench_path('resources', 'assets', 'js', 'controllers', 'dummy_controller.js'));

            $visit->waitUsing(5, 100, fn () => $visit->element('[data-dummy-version]') === null);
        });
    }
}
