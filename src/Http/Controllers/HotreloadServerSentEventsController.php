<?php

namespace HotwiredLaravel\Hotreload\Http\Controllers;

use HotwiredLaravel\Hotreload\Events\HotreloadEvent;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Sleep;

/**
 * The in-process fallback, used when the browser can't reach the daemon. It holds up one
 * PHP worker for as long as the tab is open, so we tick often: PHP only notices that
 * the client went away when a write fails, and that's what frees the worker.
 */
class HotreloadServerSentEventsController
{
    public const TICK_INTERVAL = 1.0;

    private const SLEEP_MS = 50;

    /** @var HotreloadEvent[] */
    private array $events = [];

    public function __invoke(Request $request)
    {
        $client = $request->server('REMOTE_ADDR', 'unknown') . ':' . $request->server('REMOTE_PORT', '?');

        return Response::eventStream(function () use ($client) {
            // We handle the client going away ourselves (see connection_aborted), so we can clean up...
            ignore_user_abort(true);

            $watchers = Hotreload::watchers();

            Event::listen(function (HotreloadEvent $event) {
                $this->events[] = $event;
            });

            $watchers->boot();

            $this->log("Client connected [{$client}] using the [" . Hotreload::getConfiguredWatcher() . '] watcher.');

            try {
                yield new StreamedEvent(
                    event: 'booted',
                    data: ['watcher' => Hotreload::getConfiguredWatcher()],
                );

                $lastTick = null;

                while (! connection_aborted()) {
                    $watchers->tick();

                    foreach ($this->events as $event) {
                        $this->log("Sent [{$event->eventName()}] " . json_encode($event->eventData(), JSON_UNESCAPED_SLASHES) . " to [{$client}].");

                        yield new StreamedEvent(
                            event: $event->eventName(),
                            data: $event->eventData(),
                        );
                    }

                    $this->events = [];

                    if ($lastTick === null || microtime(true) - $lastTick >= static::TICK_INTERVAL) {
                        yield new StreamedEvent(
                            event: 'tick',
                            data: ['time' => time()],
                        );

                        $lastTick = microtime(true);
                    }

                    Sleep::for(self::SLEEP_MS)->milliseconds();
                }
            } finally {
                // Runs when the client goes away, both when we notice it ourselves and when
                // Laravel's stream loop stops pulling from this generator...
                $watchers->stop();

                $this->log("Client disconnected [{$client}].");
            }
        });
    }

    /**
     * Under `php artisan serve`, the PHP server's STDERR is shown in the terminal, so that's
     * where we log to. Elsewhere (like PHP-FPM) it'd end up in the server's logs, so we don't.
     */
    private function log(string $message): void
    {
        if (! Hotreload::runningUnderServe()) {
            return;
        }

        @file_put_contents('php://stderr', "Hotreload (in-process SSE): {$message}" . PHP_EOL);
    }
}
