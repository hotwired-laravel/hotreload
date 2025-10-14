<?php

namespace HotwiredLaravel\Hotreload;

use Closure;
use HotwiredLaravel\Hotreload\Http\Controllers\HotreloadServerSentEventsController;
use HotwiredLaravel\Hotreload\Http\Middleware\HotreloadMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HotreloadServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/hotreload.php', 'hotreload'
        );
    }

    public function boot()
    {
        if (! config('hotreload.enabled')) {
            return;
        }

        $this->configureMiddleware();
        $this->configureJsFileRoutes();
        $this->configureServerSentEventsRoute();
    }

    private function configureMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddlewareToGroup('web', HotreloadMiddleware::class);
    }

    private function configureJsFileRoutes(): void
    {
        $files = [
            dirname(__DIR__) . '/dist/hotreload.esm.js',
            dirname(__DIR__) . '/dist/hotreload.esm.js.map',
            dirname(__DIR__) . '/dist/hotreload.js',
            dirname(__DIR__) . '/dist/hotreload.min.js',
            dirname(__DIR__) . '/dist/hotreload.min.js.map',
        ];

        foreach ($files as $file) {
            $basename = basename($file);

            Route::get("/hotwired-laravel-hotreload/{$basename}", $this->serveFile($file));
        }
    }

    private function serveFile(string $file): Closure
    {
        return function () use ($file) {
            return Response::file($file, [
                'Content-Type' => match ((string) str($file)->afterLast('.')) {
                    'js' => 'text/javascript',
                    'map' => 'application/json',
                    default => 'text/plain',
                },
            ]);
        };
    }

    private function configureServerSentEventsRoute(): void
    {
        Route::get('/hotwired-laravel-hotreload/sse', HotreloadServerSentEventsController::class);
    }
}
