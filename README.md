## Hotreload for Turbo Laravel

It enhances development feedback loops by detecting source code changes and updating the page _smoothly_ without requiring manual reloads.

_This package is still under development._

#### Inspiration

This package was _heavily_ inspired by the [Hotwire Spark](https://github.com/hotwired/spark) gem. The JavaScript that makes this all work in the browser was copied from there. The package was reimplemented in PHP to work with Laravel.

### Installation

```bash
composer require hotwired-laravel/hotreload --dev
```

That's it!

### Hot it works

It uses [Server-Sent Events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events) to detect changes in the application files and trigger a reload. The package registers a new route that is responsible for scanning folders looking for changes and sending events to the browser when a change is detected depending on the type of watcher:

- HTML files
- CSS
- Stimulus controllers

This package was built for Turbo Laravel, so it expects that you're using [Importmaps Laravel](https://github.com/tonysm/importmap-laravel) and something like [TailwindCSS Laravel](https://github.com/tonysm/tailwindcss-laravel). How it will work:

- **HTML change with morphing**: it fetches the new document body and updates the current body with morphing, then it reloads the Stimulus controllers in the page. It uses [idiomorph](https://github.com/bigskysoftware/idiomorph) under the hood
- **HTML change with replacing**: it reloads the page with a Turbo visit.
- **CSS change**: it fetches and reloads the stylesheet that changed.
- **Stimulus controllers change**: it fetches the Stimulus controller that changed and reloads all the controllers in the page.

### Configuration


#### HTML Replacing Method

By default, it will using morphing to replace HTML changes. You may want to use HTML replace instead of morphing on some pages that are more JS-heavy (like if you have a rich text editor like [Trix](https://trix-editor.org/) on it, for instance). To do so, you may control this on a per-page basis using a meta tag somewhere on that page's view:

```blade
@env('local')
<meta name="hotwire-hotreloading:html-reload-method" content="morph">
@endenv
```

#### Enable Logging

If you want to, you may enable logging with a meta tag on the page (you may place this somewhere global like a layout file):

```blade
@env('local')
<meta name="hotwire-hotreloading:logging" content="true">
@endenv
```

### Monitoring Paths

By default, the package will watch for changes on a few default directories (you may configure extra ones):

| Type | Description |
|---|---|
| HTML Paths | Paths where file changes should trigger an HTML reloading. By default: `resources/views`. |
| CSS Paths | Paths where file changes should trigger a CSS reloading. By default: `resources/css` and `public/dist/css` (if exists). |
| Stimulus Paths | Paths where file changes should trigger a Stimulus reloading. By default: `resources/js/controllers` (if exists). |

You may configure additional paths by calling the respective method on the `Hotreload` class in your `AppServiceProvider@boot` method:

```php
<?php

use HotwiredLaravel\Hotreload\Hotreload;

Hotreload::addHtmlPath(resource_path('images'));
Hotreload::addCssPath(resource_path('resources/sass/'));
Hotreload::addStimulusPath(resource_path('resources/js/bridge'));
```
