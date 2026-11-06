<?php

namespace HotwiredLaravel\Hotreload\Console;

use HotwiredLaravel\Hotreload\DaemonLauncher;
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

    /** @var resource|null */
    private $lock = null;

    private int $startedAt = 0;

    public function handle(): int
    {
        if (! Hotreload::enabled() && ! $this->option('force')) {
            $this->components->warn('Hotreload is disabled. Set HOTRELOAD_ENABLED=true or use --force to start the daemon anyway.');

            return self::FAILURE;
        }

        if (! $this->acquireLock()) {
            return self::SUCCESS;
        }

        $this->clients = new SplObjectStorage;

        $host = $this->bindHost();
        $port = (int) ($this->option('port') ?? Hotreload::port());

        if (! ($socket = $this->bind($host, $port))) {
            return self::FAILURE;
        }

        $port = (int) parse_url((string) $socket->getAddress(), PHP_URL_PORT);

        $this->bootWatchers();

        $this->startedAt = time();

        (new HttpServer(fn (ServerRequestInterface $request) => $this->handleRequest($request)))->listen($socket);

        $this->writeHotFile($port);

        register_shutdown_function(fn () => $this->cleanUp());

        $this->registerTimers();
        $this->registerSignals();

        $this->components->info("Hotreload daemon listening on [http://{$host}:{$port}] using the [" . Hotreload::getConfiguredWatcher() . '] watcher.');

        Loop::run();

        $this->cleanUp();

        return self::SUCCESS;
    }

    /**
     * Only one daemon may run per application (`php artisan dev` starts one as its own process,
     * and its `serve` process tries to start another). The ones that don't get the lock wait,
     * so they take over if the running daemon goes away.
     */
    private function acquireLock(): bool
    {
        @mkdir(dirname(Hotreload::hotFile()), recursive: true);

        $this->lock = fopen(Hotreload::hotFile() . '.lock', 'c');

        $waiting = false;
        $parentPid = (int) $this->option('parent-pid');

        while (! flock($this->lock, LOCK_EX | LOCK_NB)) {
            if (! $waiting) {
                $this->components->info('Another hotreload daemon is already running for this application. Waiting for it to stop...');
                $waiting = true;
            }

            if ($parentPid > 0 && ! Hotreload::processIsRunning($parentPid)) {
                return false;
            }

            sleep(1);
        }

        return true;
    }

    private function bind(string $host, int $port): ?SocketServer
    {
        try {
            return new SocketServer("{$host}:{$port}");
        } catch (Throwable $e) {
            // When the port is taken (like by another app's daemon), use a random one instead. Not
            // inside containers, though, since random ports can't be reached from the browser...
            if ($port !== 0 && ! app(DaemonLauncher::class)->runningInContainer()) {
                $this->components->warn("Hotreload daemon couldn't bind to [{$host}:{$port}] ({$e->getMessage()}). Using a random port instead.");

                return $this->bind($host, 0);
            }

            $this->components->error("Hotreload daemon failed to bind to [{$host}:{$port}]: {$e->getMessage()}");

            return null;
        }
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

    private function handleRequest(ServerRequestInterface $request): Response
    {
        return match ($request->getUri()->getPath()) {
            '/', '' => $this->connect(),
            '/up' => $this->health(),
            default => new Response(404, ['Content-Type' => 'text/plain'], 'Not Found'),
        };
    }

    /**
     * A health check endpoint, similar to Laravel's `/up` route (useful for Docker healthchecks).
     */
    private function health(): Response
    {
        return Response::json([
            'status' => 'up',
            'pid' => getmypid(),
            'watcher' => Hotreload::getConfiguredWatcher(),
            'clients' => count($this->clients),
            'uptime' => time() - $this->startedAt,
        ])->withHeader('Access-Control-Allow-Origin', '*')->withHeader('Cache-Control', 'no-cache');
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
