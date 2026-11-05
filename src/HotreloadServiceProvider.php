<?php

namespace HotwiredLaravel\Hotreload;

use Closure;
use HotwiredLaravel\Hotreload\Console\HotreloadServeCommand;
use HotwiredLaravel\Hotreload\Http\Controllers\HotreloadServerSentEventsController;
use HotwiredLaravel\Hotreload\Http\Middleware\HotreloadMiddleware;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel;
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
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([HotreloadServeCommand::class]);
        }

        if (! Hotreload::enabled()) {
            return;
        }

        $this->configureDaemonForServeCommand();
        $this->configureMiddleware();
        $this->configureJsFileRoutes();
        $this->configureServerSentEventsRoute();
    }

    /**
     * Starts the hotreload daemon alongside `php artisan serve`, which also
     * covers `php artisan dev` and `composer dev` (they run `serve`).
     */
    private function configureDaemonForServeCommand(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Event::listen(function (CommandStarting $event) {
            if ($event->command !== 'serve') {
                return;
            }

            $port = (int) config('hotreload.port', 0);

            $launcher = $this->app->make(DaemonLauncher::class);

            // Random ports inside containers (like Sail) can't be published, so we fall back
            // to the in-process SSE route unless a port was explicitly configured...
            if ($port === 0 && $launcher->runningInContainer()) {
                return;
            }

            $launcher->launch(
                host: $this->daemonHostFor((string) $event->input->getParameterOption('--host', env('SERVER_HOST', '127.0.0.1'))),
                port: $port,
                parentPid: getmypid(),
            );
        });
    }

    private function daemonHostFor(string $serveHost): string
    {
        return in_array($serveHost, ['', 'localhost', '127.0.0.1'], true) ? '127.0.0.1' : '0.0.0.0';
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
