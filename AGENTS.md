# AGENTS.md

This file provides guidance to Coding Agents when working with code in this repository.

## Project

Hotreload for Turbo Laravel (`hotwired-laravel/hotreload`) — a Laravel package that detects source code changes and updates the browser smoothly without manual reloads. Inspired by Hotwire Spark, reimplemented in PHP for Laravel. It uses Server-Sent Events (SSE) to stream file change notifications to the browser, where JavaScript reloaders apply updates.

## Commands

```bash
# Tests
vendor/bin/phpunit tests/Unit              # Unit tests
vendor/bin/phpunit tests/Browser           # Browser tests (Dusk, requires ChromeDriver)
vendor/bin/phpunit --filter=CssReloadTest  # Single test class

# JavaScript build
npm run build                              # Build JS bundles (UMD, ESM, minified) to dist/
npm run watch                              # Watch mode for JS development

# Workbench app (used for browser tests)
composer serve                             # Build, prepare, and serve the test app
composer build                             # Build the workbench app only
composer prepare                           # Run testbench discover
composer dusk:install-chromedriver         # Install/update ChromeDriver
```

## Architecture

### PHP (src/)

**Entry point:** `HotreloadServiceProvider` registers middleware, routes, and config.

**SSE flow:** `HotreloadMiddleware` injects the JS bundle into HTML responses. The client opens an SSE connection to `HotreloadServerSentEventsController`, which creates file watchers and streams change events (`ReloadHtml`, `ReloadCss`, `ReloadStimulus`).

**File watchers** implement the `Contracts\FileWatcher` interface (`boot()`, `tick()`, `stop()`):

- `InotifyFileWatcher` — uses the PHP inotify extension for efficient monitoring
- `SimpleFileWatcher` — fallback that polls file mtimes
- `FileWatchers` — composite that manages multiple watchers

**Configuration:** `Hotreload` class is the static facade for configuring watcher type and watched paths. Default paths: `resources/views`, `resources/css`, `public/dist/css`, `resources/js/controllers`. Enabled via `HOTRELOAD_ENABLED` env var.

### JavaScript (js/ → dist/)

**Reloaders** handle different change types:

- `MorphHtmlReloader` — uses Idiomorph for morphological DOM diffing (default)
- `ReplaceHtmlReloader` — full page reload via Turbo visit
- `CssReloader` — reloads stylesheet links with cache busting
- `StimulusReloader` — reimports changed Stimulus controllers

Config is read from `<meta>` tags (`hotwire-hotreload:html-reload-method`, `hotwire-hotreload:logging`).

Built with esbuild (`scripts/build.js`), outputs three bundles plus a manifest.json with version hash.

### Testing

- Unit tests (`tests/Unit/`) extend `UnitTestCase` (Orchestra Testbench)
- Browser tests (`tests/Browser/`) extend `BrowserTestCase` (Testbench Dusk) — these manipulate files in the workbench app and assert browser behavior
- Workbench app (`workbench/`) is the Laravel app used for testing
- CI runs PHP 8.3/8.4/8.5 × Laravel 12/13, with inotify extension installed
