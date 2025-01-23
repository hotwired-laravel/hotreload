<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class UnitTestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;
}
