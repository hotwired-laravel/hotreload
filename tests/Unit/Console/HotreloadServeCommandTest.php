<?php

namespace Tests\Unit\Console;

use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\UnitTestCase;

use function Orchestra\Testbench\package_path;

class HotreloadServeCommandTest extends UnitTestCase
{
    private ?Process $daemon = null;

    private ?Process $secondDaemon = null;

    protected function tearDown(): void
    {
        $this->daemon?->stop();
        $this->secondDaemon?->stop();

        parent::tearDown();
    }

    #[Test]
    public function refuses_to_start_when_disabled(): void
    {
        config()->set('hotreload.enabled', false);

        $this->artisan('hotreload:serve')->assertFailed();
    }

    #[Test]
    public function streams_file_changes_to_connected_clients(): void
    {
        @unlink(Hotreload::hotFile());

        $this->daemon = $this->startDaemon();

        $daemon = $this->waitFor(fn () => Hotreload::runningDaemon(), 'Daemon did not start: ' . $this->daemon->getErrorOutput() . $this->daemon->getOutput());

        $this->assertSame($this->daemon->getPid(), $daemon['pid']);

        $client = stream_socket_client("tcp://127.0.0.1:{$daemon['port']}", timeout: 2);
        stream_set_blocking($client, false);
        fwrite($client, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\n\r\n");

        $received = '';

        $this->waitFor(function () use ($client, &$received) {
            $received .= (string) fread($client, 8192);

            return str_contains($received, 'event: tick');
        }, 'Did not receive the initial events.');

        $this->assertStringContainsString('Content-Type: text/event-stream', $received);
        $this->assertStringContainsString('Access-Control-Allow-Origin: *', $received);
        $this->assertStringContainsString('event: booted', $received);

        $view = collect(Hotreload::htmlPaths())
            ->flatMap(fn ($path) => glob(rtrim($path, '/') . '/*.blade.php'))
            ->first();

        $this->assertNotNull($view, 'No view found to touch.');

        Sleep::for(1)->second();
        touch($view);

        $this->waitFor(function () use ($client, &$received) {
            $received .= (string) fread($client, 8192);

            return str_contains($received, 'event: reload_html');
        }, 'Did not receive the reload_html event.');

        $this->assertStringContainsString(basename($view), $received);

        fclose($client);

        $this->daemon->signal(SIGTERM);
        $this->daemon->wait();

        $this->assertFileDoesNotExist(Hotreload::hotFile());
    }

    #[Test]
    public function only_one_daemon_runs_per_application(): void
    {
        @unlink(Hotreload::hotFile());

        $this->daemon = $this->startDaemon();

        $first = $this->waitFor(fn () => Hotreload::runningDaemon(), 'First daemon did not start.');

        $this->assertSame($this->daemon->getPid(), $first['pid']);

        $this->secondDaemon = $this->startDaemon();

        $this->waitFor(
            fn () => str_contains($this->secondDaemon->getOutput(), 'Another hotreload daemon is already running'),
            'Second daemon did not wait for the lock: ' . $this->secondDaemon->getErrorOutput() . $this->secondDaemon->getOutput(),
        );

        $this->assertSame($first, Hotreload::runningDaemon());

        // When the first daemon stops, the waiting one takes over...
        $this->daemon->signal(SIGTERM);
        $this->daemon->wait();

        $second = $this->waitFor(fn () => Hotreload::runningDaemon(), 'Second daemon did not take over.');

        $this->assertSame($this->secondDaemon->getPid(), $second['pid']);
    }

    #[Test]
    public function falls_back_to_a_random_port_when_the_port_is_taken(): void
    {
        @unlink(Hotreload::hotFile());

        $taken = stream_socket_server('tcp://127.0.0.1:0');
        $takenPort = (int) parse_url('tcp://' . stream_socket_get_name($taken, false), PHP_URL_PORT);

        try {
            $this->daemon = $this->startDaemon($takenPort);

            $daemon = $this->waitFor(fn () => Hotreload::runningDaemon(), 'Daemon did not start: ' . $this->daemon->getErrorOutput() . $this->daemon->getOutput());

            $this->assertNotSame($takenPort, $daemon['port']);
            $this->assertStringContainsString('Using a random port instead', $this->daemon->getOutput());
        } finally {
            fclose($taken);
        }
    }

    private function startDaemon(int $port = 0): Process
    {
        $daemon = new Process(
            [PHP_BINARY, package_path('vendor', 'bin', 'testbench'), 'hotreload:serve', "--port={$port}"],
            package_path(),
            ['HOTRELOAD_ENABLED' => 'true'],
        );

        $daemon->start();

        return $daemon;
    }

    private function waitFor(callable $callback, string $message): mixed
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if ($result = $callback()) {
                return $result;
            }

            usleep(50_000);
        }

        $this->fail($message);
    }
}
