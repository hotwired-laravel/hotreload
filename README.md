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

Then, run the install command:

```bash
php artisan hotreload:install
```

It adds the daemon's files to your `.gitignore` and, if you have a Compose file (like when using Laravel Sail), publishes the [daemon's port](#laravel-sail-and-docker) on your application's service. It's safe to run more than once.

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

### How it works

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

The package automatically starts a small hotreload daemon while you develop. The daemon watches your files and streams changes to the browser on its own port, so it never holds up the PHP server's workers. There's nothing to configure:

- **`php artisan dev` / `composer dev`** (Laravel 13.16+): the daemon runs as its own `hotreload` process, next to `server`, `queue`, `logs`, and `vite`. You can see it with `php artisan dev:list`, or skip it with `DevCommands::except('hotreload')`.
- **`php artisan serve`**: the daemon is started alongside the server and stops when the server stops.

By default, the daemon listens on port `7357`. You may change it with the `HOTRELOAD_PORT` env var (use `0` for a random port):

```env
HOTRELOAD_PORT=7358
```

If the default port is already taken (for instance, by another app's daemon), the daemon falls back to a random port, so you can run multiple apps side by side. When you set `HOTRELOAD_PORT` (or `HOTRELOAD_URL`) explicitly, the daemon uses that exact port and fails to start if it's taken, since something (like a proxy or a published port) expects it there.

If the daemon restarts (even on a different port), open tabs reconnect to it on their own. Pages rendered while the daemon is still starting (like right after you run `php artisan serve`) wait a few seconds for it before falling back to the [in-process SSE route](#the-in-process-fallback).

The daemon logs what it does to the terminal, next to the server's requests: clients connecting and disconnecting, the events it sends, and when it starts or stops watching for changes. Pass `-v` (to `serve` or `hotreload:serve`) to also see ticks and health checks:

```
  2026-11-15 16:40:50 hotreload Client connected [127.0.0.1:34966] (1 client connected).
  2026-11-15 16:40:50 hotreload Watching for changes using the [inotify] watcher.
  2026-11-15 16:40:52 hotreload Sent reload_html {"path":"/welcome.blade.php"} to 1 client.
  2026-11-15 16:40:56 hotreload Client disconnected [127.0.0.1:34966] (0 clients connected).
```

You may also start the daemon manually with `php artisan hotreload:serve`.

The daemon exposes a health check endpoint at `/up` (similar to Laravel's), which returns a `200` response with some details about the daemon:

```bash
curl http://localhost:7357/up
```

```json
{
    "status": "up",
    "pid": 1234,
    "watcher": "inotify",
    "clients": 1,
    "uptime": 42
}
```

When the daemon isn't running (for instance, when your app is served by Herd, Valet, or nginx + PHP-FPM), the browser falls back to an SSE route served by your app. That route keeps one PHP worker busy per open tab, so make sure your server has more than one worker (see [the in-process fallback](#the-in-process-fallback)).

#### Laravel Sail and Docker

The daemon also starts inside containers. To make it reachable from the browser, its port must be published. The `hotreload:install` command does that for you on the service named by the `APP_SERVICE` env var (`laravel.test` by default). Recreate the container afterwards (like with `sail up -d`) for the port to take effect.

If you prefer, you may publish the port manually in your Compose file:

```yaml
services:
    laravel.test:
        ports:
            - '${HOTRELOAD_PORT:-7357}:${HOTRELOAD_PORT:-7357}'
```

Inside containers, the daemon doesn't fall back to a random port when its port is taken, since random ports can't be reached from the browser.

If the published port differs from the one inside the container (or the daemon sits behind a proxy or runs in another container), set the URL the browser should use:

```env
HOTRELOAD_URL=http://localhost:7357
```

When `HOTRELOAD_URL` is set, the browser always tries it first, even if the app can't see the daemon, and falls back to the in-process SSE route when it can't connect.

If you don't publish the port, the browser falls back to the [in-process SSE route](#the-in-process-fallback).

#### The In-Process Fallback

When the browser can't reach the daemon, it uses an SSE route served by your app, which keeps one PHP worker busy for each open tab (it's freed about a second after the tab closes). Under `php artisan serve`, its connections and events are logged to the terminal as well. When using `php artisan serve`, set the number of PHP server workers in your `.env` file:

```env
PHP_CLI_SERVER_WORKERS=4
```

Laravel only respects that setting when the server runs with the `--no-reload` option, so you must also pass it, otherwise the SSE connection blocks the only worker and your app hangs:

```bash
php artisan serve --no-reload
```

On Laravel Sail, you may do that by setting the command Sail uses to start the server in your `.env` file:

```env
SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80 --no-reload"
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
