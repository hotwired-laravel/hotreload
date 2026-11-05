<?php

namespace Tests\Unit;

use HotwiredLaravel\Hotreload\DaemonLauncher;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\UnitTestCase;

class DaemonAutoStartTest extends UnitTestCase
{
    private FakeDaemonLauncher $launcher;

    private array $devCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(DaemonLauncher::class, $this->launcher = new FakeDaemonLauncher);

        $this->devCommands = (new ReflectionProperty(DevCommands::class, 'commands'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(DevCommands::class, 'commands'))->setValue(null, $this->devCommands);

        parent::tearDown();
    }

    #[Test]
    public function registers_daemon_as_dev_command(): void
    {
        $this->assertSame(
            'php artisan hotreload:serve --host=127.0.0.1 --port=7357',
            $this->devCommand('hotreload')['command'] ?? null,
        );
    }

    #[Test]
    public function dev_command_uses_configured_port(): void
    {
        config()->set('hotreload.port', 7777);

        $this->rebootDevCommands();

        $this->assertSame('php artisan hotreload:serve --host=127.0.0.1 --port=7777', $this->devCommand('hotreload')['command']);
    }

    #[Test]
    public function dev_command_listens_on_all_interfaces_inside_containers(): void
    {
        $this->launcher->inContainer = true;

        $this->rebootDevCommands();

        $this->assertSame('php artisan hotreload:serve --host=0.0.0.0 --port=7357', $this->devCommand('hotreload')['command']);
    }

    #[Test]
    public function doesnt_register_dev_command_with_random_port_inside_containers(): void
    {
        $this->launcher->inContainer = true;

        config()->set('hotreload.port', 0);

        $this->rebootDevCommands();

        $this->assertNull($this->devCommand('hotreload'));
    }

    #[Test]
    public function doesnt_register_dev_command_when_disabled(): void
    {
        config()->set('hotreload.enabled', false);

        $this->rebootDevCommands();

        $this->assertNull($this->devCommand('hotreload'));
    }

    #[Test]
    public function launches_daemon_when_serve_starts(): void
    {
        $this->dispatchCommandStarting('serve');

        $this->assertEquals([
            ['host' => '127.0.0.1', 'port' => 7357, 'parentPid' => getmypid()],
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

        config()->set('hotreload.port', 0);

        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertEmpty($this->launcher->launches);
    }

    #[Test]
    public function launches_inside_containers_with_the_default_port(): void
    {
        $this->launcher->inContainer = true;

        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertSame(['host' => '0.0.0.0', 'port' => 7357, 'parentPid' => getmypid()], $this->launcher->launches[0]);
    }

    /**
     * Clears the registered dev commands and registers ours again.
     */
    private function rebootDevCommands(): void
    {
        (new ReflectionProperty(DevCommands::class, 'commands'))->setValue(null, []);

        Hotreload::registerDevCommands();
    }

    private function devCommand(string $name): ?array
    {
        return collect(DevCommands::commands())->firstWhere('name', $name);
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
