<?php

namespace HotwiredLaravel\Hotreload;

use Closure;
use HotwiredLaravel\Hotreload\Contracts\FileWatcher;
use HotwiredLaravel\Hotreload\Events\ReloadCss;
use HotwiredLaravel\Hotreload\Events\ReloadHtml;
use HotwiredLaravel\Hotreload\Events\ReloadStimulus;
use HotwiredLaravel\Hotreload\Exceptions\HotreloadException;
use HotwiredLaravel\Hotreload\Watchers\InotifyFileWatcher;
use HotwiredLaravel\Hotreload\Watchers\SimpleFileWatcher;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;

class Hotreload
{
    protected static $htmlPaths = [];
    protected static $stimulusPaths = [];
    protected static $cssPaths = [];
    protected static ?bool $runningInContainer = null;

    public const DEFAULT_PORT = 7357;

    public static function enabled(): bool
    {
        return (bool) config('hotreload.enabled');
    }

    /**
     * Registers the hotreload daemon as a `php artisan dev` process (Laravel 13.16+).
     */
    public static function registerDevCommands(): void
    {
        if (class_exists(DevCommands::class) && static::enabled() && static::shouldStartDaemon()) {
            DevCommands::artisan('hotreload:serve --host=' . static::daemonHost(), 'hotreload');
        }
    }

    /**
     * Random ports inside containers (like Sail) can't be published, so when the port is
     * set to 0 we fall back to the in-process SSE route there.
     */
    public static function shouldStartDaemon(): bool
    {
        return static::port() !== 0
            || ! static::runningInContainer();
    }

    /**
     * The interface the daemon binds to. Inside containers, it must listen on all interfaces so
     * published ports can reach it. Otherwise, it follows the host `php artisan serve` uses.
     */
    public static function daemonHost(?string $serveHost = null): string
    {
        if (static::runningInContainer()) {
            return '0.0.0.0';
        }

        return in_array($serveHost, [null, '', 'localhost', '127.0.0.1'], true) ? '127.0.0.1' : '0.0.0.0';
    }

    public static function runningInContainer(): bool
    {
        return static::$runningInContainer ?? (is_file('/.dockerenv') || (bool) env('LARAVEL_SAIL'));
    }

    /**
     * Overrides the container detection (useful for testing). Pass null to detect it again.
     */
    public static function assumeRunningInContainer(?bool $runningInContainer = true): void
    {
        static::$runningInContainer = $runningInContainer;
    }

    public static function port(): int
    {
        return (int) (config('hotreload.port') ?? static::DEFAULT_PORT);
    }

    /**
     * Whether the port (or the URL the browser uses) was explicitly configured. In that case,
     * the daemon must use that exact port instead of falling back to a random one.
     */
    public static function hasExplicitPort(): bool
    {
        return filled(config('hotreload.port')) || filled(config('hotreload.url'));
    }

    public static function hotFile(): string
    {
        return storage_path('framework/hotreload.json');
    }

    public static function lockFile(): string
    {
        return static::hotFile() . '.lock';
    }

    /**
     * Returns the running daemon's info (port and pid), or null when no daemon is running.
     *
     * @return array{port: int, pid: int}|null
     */
    public static function runningDaemon(): ?array
    {
        if (! is_file($file = static::hotFile()) || ! static::daemonHoldsLock()) {
            return null;
        }

        $daemon = json_decode((string) @file_get_contents($file), true);

        if (! is_array($daemon) || ! isset($daemon['port'], $daemon['pid'])) {
            return null;
        }

        return ['port' => (int) $daemon['port'], 'pid' => (int) $daemon['pid']];
    }

    /**
     * The URL the browser should use to connect to the daemon, or null when there's no daemon.
     * A configured URL always wins, since the daemon may run somewhere we can't see (like
     * another container). When it's unreachable, the browser falls back to the SSE route.
     */
    public static function daemonUrl(string $requestHost): ?string
    {
        if (filled($url = config('hotreload.url'))) {
            return $url;
        }

        if (! ($daemon = static::runningDaemon())) {
            return null;
        }

        return "http://{$requestHost}:{$daemon['port']}";
    }

    /**
     * Whether we can tell if another process is still running (see processIsRunning).
     */
    public static function canCheckProcesses(): bool
    {
        return function_exists('posix_kill') || is_dir('/proc/self');
    }

    public static function processIsRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            // EPERM means the process exists but belongs to another user...
            return posix_get_last_error() === 1;
        }

        if (is_dir('/proc/self')) {
            return is_dir("/proc/{$pid}");
        }

        // We can't check, so we assume it's running (see canCheckProcesses)...
        return true;
    }

    public static function withInotifyWatcher(): void
    {
        config()->set('hotwire-hotreload.watcher', 'inotify');
    }

    public static function withSimpleWatcher(): void
    {
        config()->set('hotwire-hotreload.watcher', 'simple');
    }

    public static function addHtmlPath(string $path): void
    {
        static::$htmlPaths[] = $path;
    }

    public static function addStimulusPath(string $path): void
    {
        static::$stimulusPaths[] = $path;
    }

    public static function addCssPath(string $path): void
    {
        static::$cssPaths[] = $path;
    }

    public static function htmlPaths(): array
    {
        return array_values(array_merge(static::defaultPaths('html'), static::$htmlPaths));
    }

    public static function stimulusPaths(): array
    {
        return array_values(array_merge(static::defaultPaths('stimulus'), static::$stimulusPaths));
    }

    public static function cssPaths(): array
    {
        return array_values(array_merge(static::defaultPaths('css'), static::$cssPaths));
    }

    public static function watchers(): FileWatchers
    {
        return new FileWatchers([
            ...collect(static::htmlPaths())->map(fn ($path) => static::watcherFor(
                $path,
                onChange: fn ($file) => Event::dispatch(new ReloadHtml(str_replace($path, '/', $file))),
            ))->all(),
            ...collect(static::stimulusPaths())->map(fn ($path) => static::watcherFor(
                $path,
                onChange: fn ($file) => Event::dispatch(new ReloadStimulus(str_replace($path, '/', $file)))
            ))->all(),
            ...collect(static::cssPaths())->map(fn ($path) => static::watcherFor(
                $path,
                onChange: fn ($file) => Event::dispatch(new ReloadCss(str_replace($path, '/', $file)))
            ))->all(),
        ]);
    }

    public static function resetPaths(): void
    {
        static::$cssPaths = static::defaultPaths('css');
        static::$stimulusPaths = static::defaultPaths('stimulus');
        static::$htmlPaths = static::defaultPaths('html');
    }

    public static function getConfiguredWatcher(): string
    {
        return config('hotwire-hotreload.watcher', extension_loaded('inotify') ? 'inotify' : 'simple');
    }

    /**
     * The running daemon holds an exclusive lock for as long as it lives (the OS releases it
     * when the process dies), so this works even when it couldn't clean up its hot file.
     */
    protected static function daemonHoldsLock(): bool
    {
        if (! ($lock = @fopen(static::lockFile(), 'r'))) {
            return false;
        }

        try {
            if (flock($lock, LOCK_SH | LOCK_NB)) {
                flock($lock, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($lock);
        }
    }

    protected static function defaultPaths(string $type): array
    {
        return [
            'html' => array_values(array_filter([
                resource_path('views/'),
            ], 'is_dir')),
            'css' => array_values(array_filter([
                resource_path('css/'),
                public_path('dist/css/'),
            ], 'is_dir')),
            'stimulus' => array_values(array_filter([
                resource_path('js/controllers/'),
            ], 'is_dir')),
        ][$type];
    }

    protected static function watcherFor(string $path, Closure $onChange): FileWatcher
    {
        if (static::getConfiguredWatcher() === 'inotify' && ! extension_loaded('inotify')) {
            throw HotreloadException::inotifyExtensionMissing();
        }

        return match (static::getConfiguredWatcher()) {
            'inotify' => new InotifyFileWatcher($path, $onChange),
            default => new SimpleFileWatcher($path, $onChange),
        };
    }
}
