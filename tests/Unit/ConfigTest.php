<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class ConfigTest extends UnitTestCase
{
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value);
        }

        parent::tearDown();
    }

    public static function environments(): array
    {
        return [
            'local' => ['local', null, true],
            'production' => ['production', null, false],
            'testing' => ['testing', null, false],
            'missing APP_ENV' => [null, null, false],
            'explicitly enabled' => ['production', 'true', true],
            'explicitly disabled' => ['local', 'false', false],
        ];
    }

    #[Test]
    #[DataProvider('environments')]
    public function enabled_defaults_to_local_environment_only(?string $appEnv, ?string $enabled, bool $expected): void
    {
        $this->setEnv('APP_ENV', $appEnv);
        $this->setEnv('HOTRELOAD_ENABLED', $enabled);

        $config = require dirname(__DIR__, 2) . '/config/hotreload.php';

        $this->assertSame($expected, (bool) $config['enabled']);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = getenv($key) === false ? null : getenv($key);
        }

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
}
