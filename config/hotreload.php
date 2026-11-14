<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Hotreload is enabled by default only in the "local" environment. Set the
    | HOTRELOAD_ENABLED env var to explicitly enable or disable it.
    |
    */

    'enabled' => env('HOTRELOAD_ENABLED', env('APP_ENV', 'production') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Daemon Port
    |--------------------------------------------------------------------------
    |
    | The port the hotreload daemon binds to (defaults to 7357). When the default
    | port is taken (like by another app's daemon), the daemon falls back to a
    | random port. It won't do that when you set the port (or the URL below)
    | explicitly, nor inside containers. Set it to 0 to use a random port.
    |
    */

    'port' => env('HOTRELOAD_PORT'),

    /*
    |--------------------------------------------------------------------------
    | Daemon Public URL
    |--------------------------------------------------------------------------
    |
    | The URL the browser uses to connect to the daemon. By default, it's built
    | from the current request's host and the daemon's port. Useful when the
    | daemon runs behind a proxy or inside a container.
    |
    */

    'url' => env('HOTRELOAD_URL'),
];
