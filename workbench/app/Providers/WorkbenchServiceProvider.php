<?php

namespace Workbench\App\Providers;

use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Support\ServiceProvider;

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
        Hotreload::addCssPath(base_path('workbench/resources/css'));
        Hotreload::addHtmlPath(base_path('workbench/resources/html'));
        Hotreload::addStimulusPath(base_path('workbench/resources/js/controllers'));
    }
}
