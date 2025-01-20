<?php

namespace Workbench\App\Providers;

use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Support\ServiceProvider;
use Laravel\Dusk\Browser;

use function Orchestra\Testbench\workbench_path;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Hotreload::addCssPath(workbench_path('resources/assets/css'));
        Hotreload::addHtmlPath(workbench_path('resources/views'));
        Hotreload::addStimulusPath(workbench_path('resources/assets/js/controllers'));

        Browser::macro('waitForHotreload', function (?int $seconds = null) {
            return $this->waitUsing($seconds, 100, fn () => $this->script('return document.querySelector("[data-hotwire-hotreload-ready]") ? true : false') == true);
        });
    }
}
