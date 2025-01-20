<?php

namespace Tests;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Dusk\Options;
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

    protected $waitedBeforeChanges = false;

    #[Override]
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            $this->clearViews();
        });

        $this->beforeApplicationDestroyed(function () {
            $this->clearViews();
        });

        Browser::$waitSeconds = env('CI') ? 10 : Browser::$waitSeconds;

        $this->waitedBeforeChanges = false;

        $this->cleanUpFixtures();

        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->restoreChangedFiles();

        parent::tearDown();
    }

    public static function defineWebDriverOptions()
    {
        Options::windowSize(1024, 768);
    }

    protected function clearViews(): void
    {
        Artisan::call('view:clear');
    }

    /**
     * Create a new Browser instance.
     *
     * @param  \Facebook\WebDriver\Remote\RemoteWebDriver  $driver
     * @return \Tests\Browser
     */
    #[Override]
    protected function newBrowser($driver)
    {
        return new Browser($driver);
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
        $this->waitBeforeChanging();

        $basename = basename($original);

        $moved = package_path('tests', 'fixtures', 'files', $basename);

        File::copy($original, $moved);

        $this->changedFiles[$moved] = $original;

        File::replaceInFile($search, $replace, $original);
    }

    protected function addFile(string $file, string $contents): void
    {
        $this->waitBeforeChanging();

        $this->newFiles[] = $file;

        File::put($file, $contents);
    }

    protected function deleteFile(string $original): void
    {
        $this->waitBeforeChanging();

        $basename = basename($original);

        $moved = package_path('tests', 'fixtures', 'files', $basename);

        File::copy($original, $moved);

        $this->deletedFiles[$moved] = $original;

        @unlink($original);
    }

    protected function waitBeforeChanging(): void
    {
        if (env('CI') && ! $this->waitedBeforeChanges) {
            Sleep::for(1000)->milliseconds();
            $this->waitedBeforeChanges = true;
        }
    }
}
