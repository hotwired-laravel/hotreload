<?php

namespace HotwiredLaravel\Hotreload\Http\Controllers;

use HotwiredLaravel\Hotreload\Events\HotreloadEvent;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Sleep;

class HotreloadServerSentEventsController
{
    /** @var HotreloadEvent[] */
    private array $events = [];

    public function __invoke()
    {
        return Response::stream(function () {
            $watchers = Hotreload::watchers();

            $watchers->boot();

            $ticks = 0;

            Event::listen(function (HotreloadEvent $event) {
                $this->events[] = $event;
            });

            register_shutdown_function(function () use ($watchers) {
                $watchers->stop();
            });

            while (true) {
                $watchers->tick();

                $this->sendEvents();

                if ($ticks === 0 || $ticks === 100) {
                    $this->send('tick', ['time' => time()]);

                    $ticks = 0;
                }

                if (connection_aborted()) {
                    $watchers->stop();
                    break;
                }

                $ticks++;
                Sleep::for(50)->milliseconds();
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    private function send($event, $data): void
    {
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);

        echo "event: {$event}\n";
        echo "data: {$data}\n\n";

        if (ob_get_contents()) {
            ob_end_flush();
        }

        flush();
    }

    private function sendEvents(): void
    {
        foreach ($this->events as $event) {
            $this->send($event->eventName(), $event->eventData());
        }

        $this->events = [];
    }
}
