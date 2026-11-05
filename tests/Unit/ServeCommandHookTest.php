<?php

namespace Tests\Unit;

use HotwiredLaravel\Hotreload\DaemonLauncher;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\UnitTestCase;

class ServeCommandHookTest extends UnitTestCase
{
    private FakeDaemonLauncher $launcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(DaemonLauncher::class, $this->launcher = new FakeDaemonLauncher);
    }

    #[Test]
    public function launches_daemon_when_serve_starts(): void
    {
        $this->dispatchCommandStarting('serve');

        $this->assertEquals([
            ['host' => '127.0.0.1', 'port' => 0, 'parentPid' => getmypid()],
        ], $this->launcher->launches);
    }

    #[Test]
    public function binds_to_localhost_when_serve_uses_localhost(): void
    {
        // This is how `php artisan dev` starts the server...
        $this->dispatchCommandStarting('serve', '--host=localhost');

        $this->assertSame('127.0.0.1', $this->launcher->launches[0]['host']);
    }

    #[Test]
    public function binds_to_all_interfaces_when_serve_is_exposed(): void
    {
        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertSame('0.0.0.0', $this->launcher->launches[0]['host']);
    }

    #[Test]
    public function uses_configured_port(): void
    {
        config()->set('hotreload.port', 7777);

        $this->dispatchCommandStarting('serve');

        $this->assertSame(7777, $this->launcher->launches[0]['port']);
    }

    #[Test]
    public function doesnt_launch_for_other_commands(): void
    {
        $this->dispatchCommandStarting('queue:work');

        $this->assertEmpty($this->launcher->launches);
    }

    #[Test]
    public function doesnt_launch_with_random_port_inside_containers(): void
    {
        $this->launcher->inContainer = true;

        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertEmpty($this->launcher->launches);
    }

    #[Test]
    public function launches_inside_containers_when_port_is_configured(): void
    {
        $this->launcher->inContainer = true;

        config()->set('hotreload.port', 7777);

        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertSame(['host' => '0.0.0.0', 'port' => 7777, 'parentPid' => getmypid()], $this->launcher->launches[0]);
    }

    private function dispatchCommandStarting(string $command, string ...$options): void
    {
        Event::dispatch(new CommandStarting(
            $command,
            new ArgvInput(['artisan', $command, ...$options]),
            new NullOutput,
        ));
    }
}

class FakeDaemonLauncher extends DaemonLauncher
{
    public array $launches = [];

    public bool $inContainer = false;

    public function launch(string $host, int $port, int $parentPid): void
    {
        $this->launches[] = ['host' => $host, 'port' => $port, 'parentPid' => $parentPid];
    }

    public function runningInContainer(): bool
    {
        return $this->inContainer;
    }
}
