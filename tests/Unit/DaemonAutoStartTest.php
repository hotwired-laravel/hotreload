<?php

namespace Tests\Unit;

use HotwiredLaravel\Hotreload\DaemonLauncher;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Console\ServeCommand;
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

    private array $passthroughVariables = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(DaemonLauncher::class, $this->launcher = new FakeDaemonLauncher);

        Hotreload::assumeRunningInContainer(false);

        $this->devCommands = (new ReflectionProperty(DevCommands::class, 'commands'))->getValue();
        $this->passthroughVariables = ServeCommand::$passthroughVariables;
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(DevCommands::class, 'commands'))->setValue(null, $this->devCommands);

        Hotreload::assumeRunningInContainer(null);

        ServeCommand::$passthroughVariables = $this->passthroughVariables;
        unset($_ENV[Hotreload::DAEMON_LAUNCHED_ENV], $_SERVER[Hotreload::DAEMON_LAUNCHED_ENV]);
        putenv(Hotreload::DAEMON_LAUNCHED_ENV);

        parent::tearDown();
    }

    #[Test]
    public function registers_daemon_as_dev_command(): void
    {
        $this->assertSame(
            'php artisan hotreload:serve --host=127.0.0.1',
            $this->devCommand('hotreload')['command'] ?? null,
        );
    }

    #[Test]
    public function dev_command_listens_on_all_interfaces_inside_containers(): void
    {
        Hotreload::assumeRunningInContainer();

        $this->rebootDevCommands();

        $this->assertSame('php artisan hotreload:serve --host=0.0.0.0', $this->devCommand('hotreload')['command']);
    }

    #[Test]
    public function doesnt_register_dev_command_with_random_port_inside_containers(): void
    {
        Hotreload::assumeRunningInContainer();

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
            ['host' => '127.0.0.1', 'parentPid' => getmypid()],
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
    public function binds_to_all_interfaces_inside_containers_even_when_serve_uses_localhost(): void
    {
        Hotreload::assumeRunningInContainer();

        $this->dispatchCommandStarting('serve');

        $this->assertSame('0.0.0.0', $this->launcher->launches[0]['host']);
    }

    #[Test]
    public function tells_the_server_workers_the_daemon_is_coming_up(): void
    {
        $this->dispatchCommandStarting('serve');

        $this->assertSame('1', $_ENV[Hotreload::DAEMON_LAUNCHED_ENV] ?? null);
        $this->assertSame('1', getenv(Hotreload::DAEMON_LAUNCHED_ENV));
        $this->assertContains(Hotreload::DAEMON_LAUNCHED_ENV, ServeCommand::$passthroughVariables);
    }

    #[Test]
    public function doesnt_tell_the_server_workers_about_the_daemon_when_it_failed_to_launch(): void
    {
        $this->launcher->succeeds = false;

        $this->dispatchCommandStarting('serve');

        $this->assertArrayNotHasKey(Hotreload::DAEMON_LAUNCHED_ENV, $_ENV);
        $this->assertFalse(getenv(Hotreload::DAEMON_LAUNCHED_ENV));
        $this->assertNotContains(Hotreload::DAEMON_LAUNCHED_ENV, ServeCommand::$passthroughVariables);
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
        Hotreload::assumeRunningInContainer();

        config()->set('hotreload.port', 0);

        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertEmpty($this->launcher->launches);
    }

    #[Test]
    public function launches_inside_containers_with_the_default_port(): void
    {
        Hotreload::assumeRunningInContainer();

        $this->dispatchCommandStarting('serve', '--host=0.0.0.0');

        $this->assertSame(['host' => '0.0.0.0', 'parentPid' => getmypid()], $this->launcher->launches[0]);
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

    public bool $succeeds = true;

    public function launch(string $host, int $parentPid, bool $verbose = false): bool
    {
        $this->launches[] = ['host' => $host, 'parentPid' => $parentPid];

        return $this->succeeds;
    }
}
