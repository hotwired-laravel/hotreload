<?php

namespace HotwiredLaravel\Hotreload;

use HotwiredLaravel\Hotreload\Events\ReloadCss;
use HotwiredLaravel\Hotreload\Events\ReloadHtml;
use HotwiredLaravel\Hotreload\Events\ReloadStimulus;
use Illuminate\Support\Facades\Event;

class Hotreload
{
    private static $htmlPaths = [];
    private static $stimulusPaths = [];
    private static $cssPaths = [];

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
        return array_filter(array_merge([
            resource_path('views/'),
        ], static::$htmlPaths), 'is_dir');
    }

    public static function stimulusPaths(): array
    {
        return array_filter(array_merge([
            resource_path('js/controllers/'),
        ], static::$stimulusPaths), 'is_dir');
    }

    public static function cssPaths(): array
    {
        return array_filter(array_merge([
            resource_path('css/'),
            public_path('dist/css/'),
        ], static::$cssPaths), 'is_dir');
    }

    public static function watchers(): FileWatchers
    {
        return new FileWatchers([
            ...collect(static::htmlPaths())
                ->map(fn ($path) => new FileWatcher($path, onChange: fn ($file) => Event::dispatch(new ReloadHtml(str_replace($path, '/', $file)))))
                ->all(),
            ...collect(static::stimulusPaths())
                ->map(fn ($path) => new FileWatcher(
                    $path,
                    onChange: fn ($file) => Event::dispatch(new ReloadStimulus(str_replace($path, '/', $file)))
                ))
                ->all(),
            ...collect(static::cssPaths())->map(fn ($path) => new FileWatcher($path, onChange: fn ($file) => Event::dispatch(new ReloadCss(str_replace($path, '/', $file)))))->all(),
        ]);
    }
}
