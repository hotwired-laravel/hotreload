## Hotreload for Turbo Laravel

<div>
    <a href="https://github.com/hotwired-laravel/hotreload/actions">
        <img src="https://github.com/hotwired-laravel/hotreload/workflows/tests/badge.svg" alt="Build Status">
    </a>
    <a href="https://packagist.org/packages/hotwired-laravel/hotreload">
        <img src="https://img.shields.io/packagist/v/hotwired-laravel/hotreload" alt="Latest Stable Version">
    </a>
    <a href="https://packagist.org/packages/hotwired-laravel/hotreload">
        <img src="https://img.shields.io/packagist/l/hotwired-laravel/hotreload" alt="License">
    </a>
</div>

It enhances development feedback loops by detecting source code changes and updating the page _smoothly_ without requiring manual reloads.

_This package is still under development._

#### Inspiration

This package was _heavily_ inspired by the [Hotwire Spark](https://github.com/hotwired/spark) gem. The JavaScript that makes this all work in the browser was copied from there. The package was reimplemented in PHP to work with Laravel.

### Installation

```bash
composer require hotwired-laravel/hotreload --dev
```

That's it!

By default, a simple file watcher will be used. There's another file watcher that is more efficient but requires the [inotify](https://www.php.net/inotify-init) extension, which you may install via PECL:

```bash
pecl install inotify
```

Don't forget to enable it in your `php.ini`. Since this package is for local development only, you'll only need that extension locally. Once you have the extension installed and enabled, the package should automatically pick the correct file watcher.

Optionally, you may force the use of a specific file watcher by calling this code in your `AppServiceProvider@boot` method (don't forget to wrap it for local env only):

```php
<?php

use HotwiredLaravel\Hotreload\Hotreload;

if (app()->environment('local') && class_exists(Hotreload::class)) {
    Hotreload::withInotifyWatcher();

    // or the simple one...

    Hotreload::withSimpleWatcher();
}
```

### Hot it works

The package injects a script into your application via a middleware. That script will start an `EventSource` subscribed to a [Server-Sent Events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events) (aka. SSE) stream served by the [hotreload daemon](#the-hotreload-daemon), which watches files for changes in the configured directories. There are a couple of reloaders which are activated depending on which directory you configured (or the default ones):

- HTML files
- CSS
- Stimulus controllers

This package was built for Turbo Laravel, so it expects that you're using [Importmaps Laravel](https://github.com/tonysm/importmap-laravel) and something like [TailwindCSS Laravel](https://github.com/tonysm/tailwindcss-laravel). How it will work:

- **HTML change with morphing**: it fetches the new document body and updates the current body with morphing, then it reloads the Stimulus controllers in the page. It uses [idiomorph](https://github.com/bigskysoftware/idiomorph) under the hood
- **HTML change with replacing**: it reloads the page with a Turbo visit.
- **CSS change**: it fetches and reloads the stylesheet that changed.
- **Stimulus controllers change**: it fetches the Stimulus controller that changed and reloads all the controllers in the page.

### Configuration

#### Enabling and Disabling

Hotreload is enabled by default only when `APP_ENV=local`. You may explicitly enable or disable it with the `HOTRELOAD_ENABLED` env var:

```env
HOTRELOAD_ENABLED=true
```

> [!NOTE]
> Previous versions were enabled in every environment by default. If you use a custom environment name for local development (like `dev`), set `HOTRELOAD_ENABLED=true`.

#### The Hotreload Daemon

When you run `php artisan serve` (or `php artisan dev` / `composer dev`, which run `serve` under the hood), the package automatically starts a small hotreload daemon next to it. The daemon watches your files and streams changes to the browser on its own port, so it never holds up the PHP server's workers. It stops when the server stops. There's nothing to configure.

By default, the daemon binds to a random port. You may pin it to a specific port:

```env
HOTRELOAD_PORT=7071
```

You may also start the daemon manually with `php artisan hotreload:serve`.

When the daemon isn't running (for instance, when your app is served by Herd, Valet, or nginx + PHP-FPM), the browser falls back to an SSE route served by your app. That route keeps one PHP worker busy per open tab, so make sure your server has more than one worker.

#### Laravel Sail and Docker

Random ports inside containers can't be reached from the browser, so inside containers the daemon only starts when you configure a port and publish it in your `docker-compose.yml`:

```env
HOTRELOAD_PORT=7071
```

```yaml
services:
    laravel.test:
        ports:
            - '${HOTRELOAD_PORT:-7071}:7071'
```

If the published port differs from the one inside the container (or the daemon sits behind a proxy), set the URL the browser should use:

```env
HOTRELOAD_URL=http://localhost:7071
```

Without a published port, the browser falls back to the in-process SSE route. In that case, set the number of PHP server workers in your `.env` file so the SSE connection doesn't block your app:

```env
PHP_CLI_SERVER_WORKERS=4
```

#### HTML Replacing Method

By default, it will using morphing to replace HTML changes. You may want to use HTML replace instead of morphing on some pages that are more JS-heavy (like if you have a rich text editor like [Trix](https://trix-editor.org/) on it, for instance). To do so, you may control this on a per-page basis using a meta tag somewhere on that page's view:

```blade
@env('local')
<meta name="hotwire-hotreload:html-reload-method" content="replace">
@endenv
```

#### Enable Logging

If you want to, you may enable logging with a meta tag on the page (you may place this somewhere global like a layout file):

```blade
@env('local')
<meta name="hotwire-hotreload:logging" content="true">
@endenv
```

### Monitoring Paths

By default, the package will watch for changes on a few default directories (you may configure extra ones):

| Type | Description |
|---|---|
| HTML Paths | Paths where file changes should trigger an HTML reloading. By default: `resources/views`. |
| CSS Paths | Paths where file changes should trigger a CSS reloading. By default: `resources/css` and `public/dist/css` (if exists). |
| Stimulus Paths | Paths where file changes should trigger a Stimulus reloading. By default: `resources/js/controllers` (if exists). |

You may configure additional paths by calling the respective method on the `Hotreload` class in your `AppServiceProvider@boot` method (don't forget to wrap it for local env only):

```php
<?php

use HotwiredLaravel\Hotreload\Hotreload;

if (app()->environment('local') && class_exists(Hotreload::class)) {
    Hotreload::addHtmlPath(resource_path('images'));
    Hotreload::addCssPath(resource_path('sass'));
    Hotreload::addStimulusPath(resource_path('js/bridge'));
}
```
