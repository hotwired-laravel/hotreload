<?php

namespace HotwiredLaravel\Hotreload\Console;

use HotwiredLaravel\Hotreload\Events\HotreloadEvent;
use HotwiredLaravel\Hotreload\FileWatchers;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use SplObjectStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'hotreload:serve')]
class HotreloadServeCommand extends Command
{
    protected $signature = 'hotreload:serve
        {--host=127.0.0.1 : The IP address the daemon should bind to}
        {--port= : The port the daemon should bind to (defaults to the configured port, 0 means random)}
        {--parent-pid= : Stop the daemon when this process exits}
        {--force : Start the daemon even when hotreload is disabled}';

    protected $description = 'Start the hotreload daemon that streams file changes to the browser';

    /** @var SplObjectStorage<ThroughStream, null> */
    private SplObjectStorage $clients;

    private ?FileWatchers $watchers = null;

    private bool $cleanedUp = false;

    public function handle(): int
    {
        if (! Hotreload::enabled() && ! $this->option('force')) {
            $this->components->warn('Hotreload is disabled. Set HOTRELOAD_ENABLED=true or use --force to start the daemon anyway.');

            return self::FAILURE;
        }

        $this->clients = new SplObjectStorage;

        $host = $this->bindHost();
        $port = $this->option('port') ?? config('hotreload.port', 0);

        try {
            $socket = new SocketServer("{$host}:{$port}");
        } catch (Throwable $e) {
            $this->components->error("Hotreload daemon failed to bind to [{$host}:{$port}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $port = (int) parse_url((string) $socket->getAddress(), PHP_URL_PORT);

        $this->bootWatchers();

        (new HttpServer(fn (ServerRequestInterface $request) => $this->connect()))->listen($socket);

        $this->writeHotFile($port);

        register_shutdown_function(fn () => $this->cleanUp());

        $this->registerTimers();
        $this->registerSignals();

        $this->components->info("Hotreload daemon listening on [http://{$host}:{$port}] using the [" . Hotreload::getConfiguredWatcher() . '] watcher.');

        Loop::run();

        $this->cleanUp();

        return self::SUCCESS;
    }

    private function bindHost(): string
    {
        $host = (string) $this->option('host');

        return $host === 'localhost' ? '127.0.0.1' : $host;
    }

    private function bootWatchers(): void
    {
        Event::listen(function (HotreloadEvent $event) {
            if ($this->output->isVerbose()) {
                $this->line("<fg=gray>[hotreload]</> {$event->eventName()} " . json_encode($event->eventData()));
            }

            $this->broadcast($event->eventName(), $event->eventData());
        });

        $this->watchers = Hotreload::watchers();
        $this->watchers->boot();
    }

    private function connect(): Response
    {
        $stream = new ThroughStream;

        $this->clients->attach($stream);

        $stream->on('close', fn () => $this->clients->detach($stream));

        // Write the initial events on the next tick, once the response has been sent...
        Loop::futureTick(function () use ($stream) {
            $stream->write($this->frame('booted', ['watcher' => Hotreload::getConfiguredWatcher()]));
            $stream->write($this->frame('tick', ['time' => time()]));
        });

        return new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
            'X-Accel-Buffering' => 'no',
        ], $stream);
    }

    private function broadcast(string $event, array $data): void
    {
        $frame = $this->frame($event, $data);

        foreach ($this->clients as $client) {
            $client->write($frame);
        }
    }

    private function frame(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    }

    private function registerTimers(): void
    {
        Loop::addPeriodicTimer(0.05, fn () => $this->watchers->tick());

        Loop::addPeriodicTimer(5, fn () => $this->broadcast('tick', ['time' => time()]));

        if (($parentPid = (int) $this->option('parent-pid')) > 0) {
            Loop::addPeriodicTimer(1, function () use ($parentPid) {
                if (! Hotreload::processIsRunning($parentPid)) {
                    Loop::stop();
                }
            });
        }
    }

    private function registerSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            Loop::addSignal($signal, fn () => Loop::stop());
        }
    }

    private function writeHotFile(int $port): void
    {
        @mkdir(dirname(Hotreload::hotFile()), recursive: true);

        file_put_contents(Hotreload::hotFile(), json_encode([
            'port' => $port,
            'pid' => getmypid(),
        ]));
    }

    private function cleanUp(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;

        foreach ($this->clients as $client) {
            $client->end();
        }

        $this->watchers?->stop();

        // Only remove the hot file if it's ours (another daemon may have replaced it)...
        $daemon = json_decode((string) @file_get_contents(Hotreload::hotFile()), true);

        if (is_array($daemon) && ($daemon['pid'] ?? null) === getmypid()) {
            @unlink(Hotreload::hotFile());
        }
    }
}
