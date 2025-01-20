<?php

namespace Tests;

use Exception;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Dusk\TestCase;
use Override;
use PHPUnit\Framework\Attributes\BeforeClass;
use RecursiveDirectoryIterator;

use function Orchestra\Testbench\package_path;

class BrowserTestCase extends TestCase
{
    use WithWorkbench;

    protected array $changedFiles = [];

    protected array $newFiles = [];

    protected array $deletedFiles = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Browser::$waitSeconds = env('CI') ? 10 : Browser::$waitSeconds;

        $this->cleanUpFixtures();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->restoreChangedFiles();

        parent::tearDown();
    }

    #[BeforeClass()]
    protected function waitForServerToStop()
    {
        $i = 0;

        while ($this->isServerUp()) {
            sleep(1);
            $i++;

            if ($i >= 5) {
                throw new Exception('Waited too long for server to stop.');
            }
        }
    }

    protected function isServerUp()
    {
        if ($socket = @fsockopen('127.0.0.1', 8001, $errorNumber, $errorString, $timeout = 1)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    protected function cleanUpFixtures(): void
    {
        foreach (new RecursiveDirectoryIterator(package_path('tests', 'fixtures', 'files')) as $file) {
            if ($file->isDir() || str_starts_with($file->getBasename(), '.')) {
                continue;
            }

            @unlink($file->getPathname());
        }
    }

    protected function restoreChangedFiles(): void
    {
        foreach ($this->changedFiles as $moved => $original) {
            @copy($moved, $original);
            @unlink($moved);
        }

        foreach ($this->newFiles as $file) {
            @unlink($file);
        }

        foreach ($this->deletedFiles as $moved => $original) {
            @copy($moved, $original);
        }

        $this->changedFiles = [];
        $this->newFiles = [];
        $this->deletedFiles = [];
    }

    protected function editFile(string $original, string $search, string $replace): void
    {
        $basename = basename($original);

        $moved = package_path('tests', 'fixtures', 'files', $basename);

        File::copy($original, $moved);

        $this->changedFiles[$moved] = $original;

        File::replaceInFile($search, $replace, $original);
    }

    protected function addFile(string $file, string $contents): void
    {
        $this->newFiles[] = $file;

        File::put($file, $contents);
    }

    protected function deleteFile(string $original): void
    {
        $basename = basename($original);

        $moved = package_path('tests', 'fixtures', 'files', $basename);

        File::copy($original, $moved);

        $this->deletedFiles[$moved] = $original;

        @unlink($original);
    }
}
