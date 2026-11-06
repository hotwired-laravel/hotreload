<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\UnitTestCase;

class HotreloadInstallCommandTest extends UnitTestCase
{
    private const MAPPING = '${HOTRELOAD_PORT:-7357}:${HOTRELOAD_PORT:-7357}';

    private const SAIL_COMPOSE = <<<'YAML'
    # For more information: https://laravel.com/docs/sail
    services:
        laravel.test:
            image: sail-8.4/app
            ports:
                - '${APP_PORT:-80}:80'
                - '${VITE_PORT:-5173}:${VITE_PORT:-5173}'
            environment:
                LARAVEL_SAIL: 1
            depends_on:
                - mysql
        mysql:
            image: 'mysql/mysql-server:8.0'
            ports:
                - '${FORWARD_DB_PORT:-3306}:3306'
    networks:
        sail:
            driver: bridge

    YAML;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/hotreload-install-' . uniqid();

        File::ensureDirectoryExists($this->path);

        $this->app->setBasePath($this->path);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        unset($_ENV['APP_SERVICE'], $_SERVER['APP_SERVICE']);

        parent::tearDown();
    }

    #[Test]
    public function publishes_the_port_on_the_laravel_service(): void
    {
        $this->writeFile('compose.yaml', self::SAIL_COMPOSE);

        $this->artisan('hotreload:install')
            ->expectsOutputToContain('Published the hotreload port')
            ->expectsOutputToContain('sail up -d')
            ->assertSuccessful();

        $this->assertSame([
            '${APP_PORT:-80}:80',
            '${VITE_PORT:-5173}:${VITE_PORT:-5173}',
            self::MAPPING,
        ], $this->composeFile('compose.yaml')['services']['laravel.test']['ports']);

        // Everything else stays as it was, comments included...
        $this->assertSame(
            str_replace(
                "            - '\${VITE_PORT:-5173}:\${VITE_PORT:-5173}'\n",
                "            - '\${VITE_PORT:-5173}:\${VITE_PORT:-5173}'\n            - '" . self::MAPPING . "'\n",
                self::SAIL_COMPOSE,
            ),
            file_get_contents("{$this->path}/compose.yaml"),
        );
    }

    #[Test]
    public function uses_the_app_service_env_var(): void
    {
        $_ENV['APP_SERVICE'] = $_SERVER['APP_SERVICE'] = 'mysql';

        $this->writeFile('compose.yaml', self::SAIL_COMPOSE);

        $this->artisan('hotreload:install')->assertSuccessful();

        $compose = $this->composeFile('compose.yaml');

        $this->assertContains(self::MAPPING, $compose['services']['mysql']['ports']);
        $this->assertNotContains(self::MAPPING, $compose['services']['laravel.test']['ports']);
    }

    #[Test]
    #[DataProvider('composeFiles')]
    public function finds_the_compose_file(string $name): void
    {
        $this->writeFile($name, self::SAIL_COMPOSE);

        $this->artisan('hotreload:install')->assertSuccessful();

        $this->assertContains(self::MAPPING, $this->composeFile($name)['services']['laravel.test']['ports']);
    }

    public static function composeFiles(): array
    {
        return [
            ['compose.yaml'],
            ['compose.yml'],
            ['docker-compose.yaml'],
            ['docker-compose.yml'],
        ];
    }

    #[Test]
    public function adds_the_ports_key_when_the_service_has_none(): void
    {
        $this->writeFile('compose.yaml', <<<'YAML'
        services:
          laravel.test:
            image: app
          mysql:
            image: mysql
        YAML);

        $this->artisan('hotreload:install')->assertSuccessful();

        $compose = $this->composeFile('compose.yaml');

        $this->assertSame([self::MAPPING], $compose['services']['laravel.test']['ports']);
        $this->assertSame('app', $compose['services']['laravel.test']['image']);
        $this->assertArrayNotHasKey('ports', $compose['services']['mysql']);
    }

    #[Test]
    public function handles_multi_line_port_items(): void
    {
        $this->writeFile('compose.yaml', <<<'YAML'
        services:
            laravel.test:
                ports:
                    - target: 80
                      published: 8080
                image: app
        YAML);

        $this->artisan('hotreload:install')->assertSuccessful();

        $service = $this->composeFile('compose.yaml')['services']['laravel.test'];

        $this->assertSame([['target' => 80, 'published' => 8080], self::MAPPING], $service['ports']);
        $this->assertSame('app', $service['image']);
    }

    #[Test]
    public function handles_port_items_at_the_same_indent_as_the_key(): void
    {
        $this->writeFile('compose.yaml', <<<'YAML'
        services:
          laravel.test:
            ports:
            - '80:80'
            image: app
        YAML);

        $this->artisan('hotreload:install')->assertSuccessful();

        $service = $this->composeFile('compose.yaml')['services']['laravel.test'];

        $this->assertSame(['80:80', self::MAPPING], $service['ports']);
        $this->assertSame('app', $service['image']);
    }

    #[Test]
    public function handles_ports_as_the_last_key_of_the_last_service(): void
    {
        $this->writeFile('compose.yaml', "services:\n    laravel.test:\n        ports:\n            - '80:80'\n");

        $this->artisan('hotreload:install')->assertSuccessful();

        $this->assertSame(
            ['80:80', self::MAPPING],
            $this->composeFile('compose.yaml')['services']['laravel.test']['ports'],
        );
    }

    #[Test]
    public function only_matches_direct_children_of_services(): void
    {
        $contents = <<<'YAML'
        services:
            worker:
                depends_on:
                    laravel.test:
                        condition: service_started
        YAML;

        $this->writeFile('compose.yaml', $contents);

        $this->artisan('hotreload:install')
            ->expectsOutputToContain('find the [laravel.test] service')
            ->assertSuccessful();

        $this->assertSame($contents, file_get_contents("{$this->path}/compose.yaml"));
    }

    #[Test]
    public function warns_and_leaves_the_file_alone_when_the_service_is_missing(): void
    {
        $contents = "services:\n    app:\n        image: app\n";

        $this->writeFile('compose.yaml', $contents);

        $this->artisan('hotreload:install')
            ->expectsOutputToContain('find the [laravel.test] service')
            ->expectsOutputToContain("- '" . self::MAPPING . "'")
            ->assertSuccessful();

        $this->assertSame($contents, file_get_contents("{$this->path}/compose.yaml"));
    }

    #[Test]
    public function warns_and_leaves_the_file_alone_with_inline_ports(): void
    {
        $contents = "services:\n    laravel.test:\n        ports: ['80:80']\n";

        $this->writeFile('compose.yaml', $contents);

        $this->artisan('hotreload:install')
            ->expectsOutputToContain('Add the port to the service manually')
            ->assertSuccessful();

        $this->assertSame($contents, file_get_contents("{$this->path}/compose.yaml"));
    }

    #[Test]
    public function a_commented_out_port_doesnt_count_as_published(): void
    {
        $this->writeFile('compose.yaml', "services:\n    laravel.test:\n        ports:\n            # - '7357:7357'\n            - '80:80'\n");

        $this->artisan('hotreload:install')->assertSuccessful();

        $this->assertContains(self::MAPPING, $this->composeFile('compose.yaml')['services']['laravel.test']['ports']);
    }

    #[Test]
    public function running_twice_changes_nothing(): void
    {
        $this->writeFile('compose.yaml', self::SAIL_COMPOSE);
        $this->writeFile('.gitignore', "/vendor\n");

        $this->artisan('hotreload:install')->assertSuccessful();

        $compose = file_get_contents("{$this->path}/compose.yaml");
        $gitignore = file_get_contents("{$this->path}/.gitignore");

        $this->artisan('hotreload:install')
            ->expectsOutputToContain('already published')
            ->expectsOutputToContain('already ignored')
            ->assertSuccessful();

        $this->assertSame($compose, file_get_contents("{$this->path}/compose.yaml"));
        $this->assertSame($gitignore, file_get_contents("{$this->path}/.gitignore"));
    }

    #[Test]
    public function doesnt_create_files_that_dont_exist(): void
    {
        $this->artisan('hotreload:install')->assertSuccessful();

        $this->assertSame([], array_map('strval', File::allFiles($this->path, hidden: true)));
    }

    #[Test]
    public function ignores_the_daemon_files(): void
    {
        $this->writeFile('.gitignore', "/vendor\n");

        $this->artisan('hotreload:install')->assertSuccessful();

        $this->assertSame("/vendor\n/storage/framework/hotreload.json*\n", file_get_contents("{$this->path}/.gitignore"));
    }

    #[Test]
    public function handles_gitignore_without_a_trailing_new_line(): void
    {
        $this->writeFile('.gitignore', '/vendor');

        $this->artisan('hotreload:install')->assertSuccessful();

        $this->assertSame("/vendor\n/storage/framework/hotreload.json*\n", file_get_contents("{$this->path}/.gitignore"));
    }

    private function writeFile(string $name, string $contents): void
    {
        file_put_contents("{$this->path}/{$name}", $contents);
    }

    private function composeFile(string $name): array
    {
        return Yaml::parseFile("{$this->path}/{$name}");
    }
}
