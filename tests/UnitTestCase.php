<?php

namespace Tests;

use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class UnitTestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Hotreload::resetPaths();
    }
}
