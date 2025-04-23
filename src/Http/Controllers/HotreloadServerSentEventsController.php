<?php

namespace HotwiredLaravel\Hotreload\Http\Controllers;

use HotwiredLaravel\Hotreload\Events\HotreloadEvent;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Sleep;

class HotreloadServerSentEventsController
{
    /** @var HotreloadEvent[] */
    private array $events = [];

    public function __invoke()
    {
        return Response::eventStream(function () {
            $watchers = Hotreload::watchers();

            $ticks = 0;

            Event::listen(function (HotreloadEvent $event) {
                $this->events[] = $event;
            });

            $watchers->boot();

            yield new StreamedEvent(
                event: 'booted',
                data: ['watcher' => Hotreload::getConfiguredWatcher()],
            );

            while (true) {
                if (connection_aborted()) {
                    $watchers->stop();
                    break;
                }

                $watchers->tick();

                foreach ($this->events as $event) {
                    yield new StreamedEvent(
                        event: $event->eventName(),
                        data: $event->eventData(),
                    );
                }

                $this->events = [];

                if ($ticks === 0 || $ticks === 100) {
                    yield new StreamedEvent(
                        event: 'tick',
                        data: ['time' => time()],
                    );

                    $ticks = 0;
                }

                $ticks++;

                Sleep::for(50)->milliseconds();
            }
        });
    }
}
