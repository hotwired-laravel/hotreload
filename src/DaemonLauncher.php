<?php

namespace HotwiredLaravel\Hotreload;

class DaemonLauncher
{
    /** @var resource|null */
    private $process = null;

    /**
     * Starts the hotreload daemon in the background. The daemon writes directly to
     * this process' STDOUT and STDERR (no pipes), so it never blocks on a full
     * pipe buffer that nobody reads.
     */
    public function launch(string $host, int $parentPid, bool $verbose = false): bool
    {
        if ($this->process !== null) {
            return true;
        }

        if (! function_exists('proc_open')) {
            return false;
        }

        $command = [
            PHP_BINARY,
            $this->consoleScript(),
            'hotreload:serve',
            "--host={$host}",
            "--parent-pid={$parentPid}",
            ...($verbose ? ['-v'] : []),
        ];

        $process = @proc_open($command, [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => defined('STDOUT') ? STDOUT : ['file', 'php://stdout', 'w'],
            2 => defined('STDERR') ? STDERR : ['file', 'php://stderr', 'w'],
        ], $pipes, getcwd() ?: base_path());

        $this->process = is_resource($process) ? $process : null;

        return $this->process !== null;
    }

    /**
     * Asks the daemon to stop, and kills it when it doesn't in time. We never block on
     * `proc_close()` alone, since it waits for the daemon forever (hanging `serve`).
     */
    public function stop(float $timeout = 3.0): void
    {
        if ($this->process === null) {
            return;
        }

        @proc_terminate($this->process);

        $deadline = microtime(true) + $timeout;

        while (($status = @proc_get_status($this->process)) && $status['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }

        if ($status && $status['running']) {
            @proc_terminate($this->process, defined('SIGKILL') ? SIGKILL : 9);
        }

        @proc_close($this->process);

        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * The script that started the current process (`artisan`, or `vendor/bin/testbench`
     * when developing packages), so the daemon runs in the same application.
     */
    private function consoleScript(): string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['argv'][0] ?? null;

        if ($script && is_file($script)) {
            return realpath($script) ?: $script;
        }

        return base_path('artisan');
    }
}
