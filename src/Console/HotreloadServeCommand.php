<?php

namespace HotwiredLaravel\Hotreload\Console;

use DateTimeImmutable;
use DateTimeZone;
use HotwiredLaravel\Hotreload\Events\HotreloadEvent;
use HotwiredLaravel\Hotreload\FileWatchers;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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

    /** @var SplObjectStorage<ThroughStream, string> The connected clients, with their addresses. */
    private SplObjectStorage $clients;

    private ?FileWatchers $watchers = null;

    private bool $cleanedUp = false;

    /** @var resource|null */
    private $lock = null;

    private int $startedAt = 0;

    private ?DateTimeZone $systemTimezone = null;

    public function handle(): int
    {
        if (! Hotreload::enabled() && ! $this->option('force')) {
            $this->components->warn('Hotreload is disabled. Set HOTRELOAD_ENABLED=true or use --force to start the daemon anyway.');

            return self::FAILURE;
        }

        if (! $this->openLock()) {
            return self::FAILURE;
        }

        if (! $this->acquireLock()) {
            return self::SUCCESS;
        }

        $this->clients = new SplObjectStorage;

        $host = $this->bindHost();
        $port = (int) ($this->option('port') ?? Hotreload::port());
        $explicitPort = $this->option('port') !== null || Hotreload::hasExplicitPort();

        if (! ($socket = $this->bind($host, $port, $explicitPort))) {
            return self::FAILURE;
        }

        $port = (int) parse_url((string) $socket->getAddress(), PHP_URL_PORT);

        $this->listenForChanges();

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

    private function openLock(): bool
    {
        @mkdir(dirname(Hotreload::lockFile()), recursive: true);

        if (! ($this->lock = @fopen(Hotreload::lockFile(), 'c'))) {
            $this->components->error('Hotreload daemon could not open its lock file [' . Hotreload::lockFile() . ']. Make sure the directory is writable.');

            return false;
        }

        return true;
    }

    /**
     * Only one daemon may run per application (`php artisan dev` starts one as its own process,
     * and its `serve` process tries to start another). The ones that don't get the lock wait,
     * so they take over if the running daemon goes away.
     */
    private function acquireLock(): bool
    {
        $waiting = false;
        $parentPid = (int) $this->option('parent-pid');

        while (! flock($this->lock, LOCK_EX | LOCK_NB)) {
            // We can't tell when our parent exits, so we'd wait forever and outlive it...
            if ($parentPid > 0 && ! Hotreload::canCheckProcesses()) {
                return false;
            }

            if (! $waiting) {
                $this->components->info('Another hotreload daemon is already running for this application. Waiting for it to stop...');
                $waiting = true;
            }

            if ($parentPid > 0 && ! Hotreload::processIsRunning($parentPid)) {
                return false;
            }

            sleep(1);
        }

        if ($waiting) {
            $this->log('The other daemon stopped. Taking over...');
        }

        return true;
    }

    private function bind(string $host, int $port, bool $explicitPort): ?SocketServer
    {
        try {
            return new SocketServer("{$host}:{$port}");
        } catch (Throwable $e) {
            // When the default port is taken (like by another app's daemon), use a random one instead. Not when
            // the port was configured (something like a proxy or a published port expects it), nor inside
            // containers, since random ports can't be reached from the browser there...
            if ($port !== 0 && ! $explicitPort && ! Hotreload::runningInContainer()) {
                $this->components->warn("Hotreload daemon couldn't bind to [{$host}:{$port}] ({$e->getMessage()}). Using a random port instead.");

                return $this->bind($host, 0, $explicitPort);
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

    private function listenForChanges(): void
    {
        Event::listen(function (HotreloadEvent $event) {
            $clients = count($this->clients);

            $this->log("Sent <fg=yellow>{$event->eventName()}</> " . json_encode($event->eventData(), JSON_UNESCAPED_SLASHES) . ' to ' . $this->clientsCount($clients) . '.');

            $this->broadcast($event->eventName(), $event->eventData());
        });
    }

    /**
     * The watchers only run while there are connected clients, so an idle daemon doesn't keep
     * scanning the watched directories. They boot (taking a fresh snapshot) on the first client.
     */
    private function startWatching(): void
    {
        if ($this->watchers !== null) {
            return;
        }

        $this->watchers = Hotreload::watchers();
        $this->watchers->boot();

        $this->log('Watching for changes using the [' . Hotreload::getConfiguredWatcher() . '] watcher.');
    }

    private function stopWatching(): void
    {
        if ($this->watchers === null) {
            return;
        }

        $this->watchers->stop();
        $this->watchers = null;

        $this->log('Stopped watching for changes (no clients connected).');
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if (in_array($path, ['/', ''], true)) {
            return $this->connect($this->clientAddress($request));
        }

        $response = $path === '/up'
            ? $this->health()
            : new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');

        $this->log("<fg=gray>{$request->getMethod()} {$path} [{$response->getStatusCode()}] from [{$this->clientAddress($request)}]</>", verbose: true);

        return $response;
    }

    private function clientAddress(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        return ($server['REMOTE_ADDR'] ?? 'unknown') . (isset($server['REMOTE_PORT']) ? ":{$server['REMOTE_PORT']}" : '');
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

    private function connect(string $client): Response
    {
        $stream = new ThroughStream;

        $this->clients->attach($stream, $client);

        $this->log("<fg=green>Client connected</> [{$client}] (" . $this->clientsCount(count($this->clients)) . ' connected).');

        $this->startWatching();

        // The HTTP server closes the stream when the browser goes away (like closing the tab)...
        $stream->on('close', function () use ($stream, $client) {
            $this->clients->detach($stream);

            if ($this->cleanedUp) {
                return;
            }

            $this->log("<fg=red>Client disconnected</> [{$client}] (" . $this->clientsCount(count($this->clients)) . ' connected).');

            if (count($this->clients) === 0) {
                $this->stopWatching();
            }
        });

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
        Loop::addPeriodicTimer(0.05, fn () => $this->watchers?->tick());

        Loop::addPeriodicTimer(5, function () {
            if (count($this->clients) > 0) {
                $this->log('<fg=gray>Sent tick to ' . $this->clientsCount(count($this->clients)) . '.</>', verbose: true);
            }

            $this->broadcast('tick', ['time' => time()]);
        });

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

        // We're going away, so the clients' close events don't need to be logged one by one...
        $clients = iterator_to_array($this->clients);
        $this->clients = new SplObjectStorage;

        foreach ($clients as $client) {
            $client->end();
        }

        $this->stopWatching();

        $this->log('Hotreload daemon stopped' . (count($clients) ? ' (disconnected ' . $this->clientsCount(count($clients)) . ').' : '.'));

        // Only remove the hot file if it's ours (another daemon may have replaced it)...
        $daemon = json_decode((string) @file_get_contents(Hotreload::hotFile()), true);

        if (is_array($daemon) && ($daemon['pid'] ?? null) === getmypid()) {
            @unlink(Hotreload::hotFile());
        }
    }

    /**
     * The PHP server logs its requests in the system's timezone (not the app's), so we
     * use it as well. Otherwise, our lines would look out of order next to its lines.
     */
    private function systemTimezone(): DateTimeZone
    {
        if ($this->systemTimezone) {
            return $this->systemTimezone;
        }

        $name = getenv('TZ') ?: (@readlink('/etc/localtime') ? Str::after((string) readlink('/etc/localtime'), 'zoneinfo/') : null);

        try {
            return $this->systemTimezone = new DateTimeZone(ltrim((string) $name, ':') ?: date_default_timezone_get());
        } catch (Throwable) {
            return $this->systemTimezone = new DateTimeZone(date_default_timezone_get());
        }
    }

    private function clientsCount(int $count): string
    {
        return $count . ' ' . ($count === 1 ? 'client' : 'clients');
    }

    /**
     * Logs a line to the terminal, formatted like `php artisan serve`'s request log (since
     * that's usually where it shows up). Verbose lines only show with `-v`.
     */
    private function log(string $message, bool $verbose = false): void
    {
        if ($verbose && ! $this->output->isVerbose()) {
            return;
        }

        [$date, $time] = explode(' ', (new DateTimeImmutable('now', $this->systemTimezone()))->format('Y-m-d H:i:s'));

        $this->output->writeln("  <fg=gray>{$date}</> {$time} <fg=blue>hotreload</> {$message}");
    }
}
