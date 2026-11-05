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
use Illuminate\Support\Facades\Event;

class Hotreload
{
    protected static $htmlPaths = [];
    protected static $stimulusPaths = [];
    protected static $cssPaths = [];

    public static function enabled(): bool
    {
        return (bool) config('hotreload.enabled');
    }

    public static function hotFile(): string
    {
        return storage_path('framework/hotreload.json');
    }

    /**
     * Returns the running daemon's info (port and pid), or null when no daemon is running.
     *
     * @return array{port: int, pid: int}|null
     */
    public static function runningDaemon(): ?array
    {
        if (! is_file($file = static::hotFile())) {
            return null;
        }

        $daemon = json_decode((string) @file_get_contents($file), true);

        if (! is_array($daemon) || ! isset($daemon['port'], $daemon['pid'])) {
            return null;
        }

        if (! static::processIsRunning((int) $daemon['pid'])) {
            return null;
        }

        return ['port' => (int) $daemon['port'], 'pid' => (int) $daemon['pid']];
    }

    public static function processIsRunning(int $pid): bool
    {
        // Without the posix extension we can't check, so we assume it's running...
        if (! function_exists('posix_kill')) {
            return true;
        }

        if (@posix_kill($pid, 0)) {
            return true;
        }

        // EPERM means the process exists but belongs to another user...
        return posix_get_last_error() === 1;
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
