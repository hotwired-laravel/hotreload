<?php

namespace HotwiredLaravel\Hotreload\Console;

use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'hotreload:install')]
class HotreloadInstallCommand extends Command
{
    private const COMPOSE_FILES = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ];

    private const GITIGNORE_PATTERN = '/storage/framework/hotreload.json*';

    protected $signature = 'hotreload:install';

    protected $description = 'Publish the hotreload daemon port in your compose file and ignore its files in Git';

    public function handle(): int
    {
        $this->publishComposePort();
        $this->updateGitignore();

        return self::SUCCESS;
    }

    private function publishComposePort(): void
    {
        $file = collect(self::COMPOSE_FILES)
            ->map(fn (string $name) => base_path($name))
            ->first(fn (string $path) => is_file($path));

        if ($file === null) {
            return;
        }

        // The same variable (and default) Sail uses for the application's service...
        $service = (string) env('APP_SERVICE', 'laravel.test');
        $name = basename($file);

        $lines = explode("\n", (string) file_get_contents($file));

        $block = $this->findService($lines, $service);

        if ($block === null) {
            $this->warnManualStep("Couldn't find the [{$service}] service in [{$name}].", $service);

            return;
        }

        [$start, $end] = $block;

        if ($this->portIsPublished(array_slice($lines, $start, $end - $start))) {
            $this->components->info("The hotreload port is already published in [{$name}].");

            return;
        }

        $updated = $this->insertPort($lines, $start, $end);

        if ($updated === null) {
            $this->warnManualStep("Couldn't update the [{$service}] service's ports in [{$name}].", $service);

            return;
        }

        file_put_contents($file, implode("\n", $updated));

        $this->components->info("Published the hotreload port on the [{$service}] service in [{$name}].");
        $this->components->warn('Recreate the container for the port to take effect (like with `sail up -d`).');
    }

    /**
     * Returns the service's line and the line where its block ends (exclusive). Only direct
     * children of `services` match, since the name may also appear in another service's options.
     *
     * @param  array<int, string>  $lines
     * @return array{int, int}|null
     */
    private function findService(array $lines, string $service): ?array
    {
        $services = collect($lines)->search(fn (string $line) => preg_match('/^services:\s*(#.*)?$/', $line) === 1);

        if ($services === false) {
            return null;
        }

        $serviceIndent = null;
        $pattern = '/^\s*[\'"]?' . preg_quote($service, '/') . '[\'"]?:\s*(#.*)?$/';

        for ($i = $services + 1; $i < count($lines); $i++) {
            if ($this->isIgnorable($lines[$i])) {
                continue;
            }

            $indent = $this->indentOf($lines[$i]);

            if ($indent === 0) {
                return null;
            }

            $serviceIndent ??= $indent;

            if ($indent === $serviceIndent && preg_match($pattern, $lines[$i]) === 1) {
                return [$i, $this->findBlockEnd($lines, $i)];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function findBlockEnd(array $lines, int $start): int
    {
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (! $this->isIgnorable($lines[$i]) && $this->indentOf($lines[$i]) <= $this->indentOf($lines[$start])) {
                return $i;
            }
        }

        return count($lines);
    }

    /**
     * @param  array<int, string>  $block
     */
    private function portIsPublished(array $block): bool
    {
        $port = Hotreload::DEFAULT_PORT;

        return collect($block)
            ->reject(fn (string $line) => $this->isIgnorable($line))
            ->contains(fn (string $line) => preg_match("/HOTRELOAD_PORT|\b{$port}\b/", $line) === 1);
    }

    /**
     * Returns the lines with the port added, or null when we don't know how to add it.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>|null
     */
    private function insertPort(array $lines, int $start, int $end): ?array
    {
        $serviceIndent = $this->indentOf($lines[$start]);
        $keyIndent = null;

        for ($i = $start + 1; $i < $end; $i++) {
            if ($this->isIgnorable($lines[$i])) {
                continue;
            }

            $keyIndent ??= $this->indentOf($lines[$i]);

            if ($this->indentOf($lines[$i]) !== $keyIndent) {
                continue;
            }

            if (preg_match('/^\s*ports:\s*(#.*)?$/', $lines[$i]) === 1) {
                return $this->insertIntoPorts($lines, $i, $end, $keyIndent, $keyIndent + ($keyIndent - $serviceIndent));
            }

            // Inline ports (like `ports: [...]`)...
            if (preg_match('/^\s*ports:/', $lines[$i]) === 1) {
                return null;
            }
        }

        $keyIndent ??= $serviceIndent * 2;

        array_splice($lines, $start + 1, 0, [
            str_repeat(' ', $keyIndent) . 'ports:',
            str_repeat(' ', $keyIndent + ($keyIndent - $serviceIndent)) . '- ' . $this->portMapping(),
        ]);

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function insertIntoPorts(array $lines, int $ports, int $end, int $keyIndent, int $itemIndent): array
    {
        $last = $ports;
        $firstItem = true;

        for ($i = $ports + 1; $i < $end; $i++) {
            if ($this->isIgnorable($lines[$i])) {
                continue;
            }

            $indent = $this->indentOf($lines[$i]);

            // List items may sit at the same indent as the `ports` key...
            if ($indent === $keyIndent && ! str_starts_with(ltrim($lines[$i]), '-')) {
                break;
            }

            if ($firstItem) {
                $itemIndent = $indent;
                $firstItem = false;
            }

            $last = $i;
        }

        array_splice($lines, $last + 1, 0, [str_repeat(' ', $itemIndent) . '- ' . $this->portMapping()]);

        return $lines;
    }

    private function updateGitignore(): void
    {
        if (! is_file($file = base_path('.gitignore'))) {
            return;
        }

        $contents = (string) file_get_contents($file);

        if (in_array(self::GITIGNORE_PATTERN, array_map('trim', explode("\n", $contents)), true)) {
            $this->components->info('The hotreload files are already ignored in [.gitignore].');

            return;
        }

        $separator = $contents === '' || str_ends_with($contents, "\n") ? '' : "\n";

        file_put_contents($file, $contents . $separator . self::GITIGNORE_PATTERN . "\n");

        $this->components->info('Added the hotreload files to [.gitignore].');
    }

    private function warnManualStep(string $message, string $service): void
    {
        $this->components->warn("{$message} Add the port to the service manually:");

        $this->line("    {$service}:");
        $this->line('        ports:');
        $this->line('            - ' . $this->portMapping());
        $this->newLine();
    }

    private function portMapping(): string
    {
        $port = '${HOTRELOAD_PORT:-' . Hotreload::DEFAULT_PORT . '}';

        return "'{$port}:{$port}'";
    }

    private function isIgnorable(string $line): bool
    {
        $line = trim($line);

        return $line === '' || str_starts_with($line, '#');
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
