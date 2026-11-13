<?php

namespace HotwiredLaravel\Hotreload;

use Closure;
use HotwiredLaravel\Hotreload\Console\HotreloadInstallCommand;
use HotwiredLaravel\Hotreload\Console\HotreloadServeCommand;
use HotwiredLaravel\Hotreload\Http\Controllers\HotreloadServerSentEventsController;
use HotwiredLaravel\Hotreload\Http\Middleware\HotreloadMiddleware;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        $this->app->singleton(DaemonLauncher::class);

        if ($this->app->runningInConsole()) {
            Hotreload::registerDevCommands();
        }
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([HotreloadInstallCommand::class, HotreloadServeCommand::class]);
        }

        if (! Hotreload::enabled()) {
            return;
        }

        $this->configureServeCommand();
        $this->configureMiddleware();
        $this->configureJsFileRoutes();
        $this->configureServerSentEventsRoute();
    }

    /**
     * Starts the hotreload daemon alongside `php artisan serve`. When `serve` runs under
     * `php artisan dev`, the daemon's lock makes sure only one of them keeps running.
     */
    private function configureServeCommand(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Event::listen(function (CommandStarting $event) {
            if ($event->command !== 'serve' || ! Hotreload::shouldStartDaemon()) {
                return;
            }

            $this->app->make(DaemonLauncher::class)->launch(
                host: Hotreload::daemonHost((string) $event->input->getParameterOption('--host', env('SERVER_HOST', '127.0.0.1'))),
                parentPid: getmypid(),
            );
        });
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

        // The browser asks where the daemon is when it loses the connection (it may have restarted on another port)...
        Route::get('/hotwired-laravel-hotreload/daemon', fn (Request $request) => Response::json([
            'url' => Hotreload::daemonUrl($request->getHost()),
        ])->header('Cache-Control', 'no-store'));
    }
}
