<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Contracts\CommandRegistryInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Session\Console\SessionGcCommand;
use EzPhp\Session\Driver\FileSessionHandler;
use EzPhp\Session\SessionServiceProvider;
use EzPhp\Session\StartSessionMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\TestCase;

/**
 * session:gc against a FileSessionHandler in a temp directory, plus the provider
 * registering the command when the container is a command registry.
 *
 * @package Tests\Console
 */
#[CoversClass(SessionGcCommand::class)]
#[CoversClass(SessionServiceProvider::class)]
#[UsesClass(FileSessionHandler::class)]
#[UsesClass(StartSessionMiddleware::class)]
final class SessionGcCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ez-session-gc-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
        parent::tearDown();
    }

    private function writeSession(string $id, int $ageSeconds): void
    {
        $path = $this->dir . '/sess_' . $id;
        file_put_contents($path, 'data');
        touch($path, time() - $ageSeconds);
    }

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $config
     *
     * @return array{int, string, string}
     */
    private function runGc(array $args, array $config = []): array
    {
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);
        $command = new SessionGcCommand(new FileSessionHandler($this->dir), new FakeConfig($config), $errors);

        ob_start();
        $code = $command->handle($args);
        $output = (string) ob_get_clean();
        rewind($errors);

        return [$code, $output, (string) stream_get_contents($errors)];
    }

    public function test_deletes_sessions_idle_longer_than_the_configured_lifetime(): void
    {
        $this->writeSession('old', 7200);
        $this->writeSession('fresh', 10);

        [$code, $output] = $this->runGc([], ['session.idle_lifetime' => 3600]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deleted 1 expired session(s) idle for more than 3600 s.', $output);
        $this->assertFileDoesNotExist($this->dir . '/sess_old');
        $this->assertFileExists($this->dir . '/sess_fresh');
    }

    public function test_lifetime_option_overrides_the_config(): void
    {
        $this->writeSession('a', 120);

        [$code, $output] = $this->runGc(['--lifetime=60'], ['session.idle_lifetime' => 3600]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deleted 1', $output);
    }

    public function test_invalid_lifetime_is_rejected(): void
    {
        [$code, , $errors] = $this->runGc(['--lifetime=soon']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--lifetime must be a positive number', $errors);
    }

    public function test_name_and_help(): void
    {
        $command = new SessionGcCommand(new FileSessionHandler($this->dir), new FakeConfig());

        $this->assertSame('session:gc', $command->getName());
        $this->assertNotSame('', $command->getDescription());
        $this->assertStringContainsString('--lifetime', $command->getHelp());
    }

    public function test_provider_boot_registers_the_command(): void
    {
        $registry = new class () implements ContainerInterface, CommandRegistryInterface {
            /** @var list<class-string> */
            public array $commands = [];

            public function bind(string $abstract, string|callable|null $factory = null): static
            {
                return $this;
            }

            public function make(string $abstract): mixed
            {
                throw new \LogicException('boot() must not resolve anything.');
            }

            public function has(string $abstract): bool
            {
                return false;
            }

            public function instance(string $abstract, object $instance): void
            {
            }

            public function registerCommand(string $commandClass): static
            {
                $this->commands[] = $commandClass;

                return $this;
            }

            public function getCommands(): array
            {
                return $this->commands;
            }
        };

        (new SessionServiceProvider($registry))->boot();

        $this->assertSame([SessionGcCommand::class], $registry->getCommands());
    }

    public function test_gc_lifetime_falls_back_to_the_store_ttl_without_an_idle_lifetime(): void
    {
        $this->assertSame(600, SessionServiceProvider::gcLifetime(new FakeConfig(['session.idle_lifetime' => 600])));
        $this->assertGreaterThanOrEqual(
            7200,
            SessionServiceProvider::gcLifetime(new FakeConfig(['session.idle_lifetime' => 0, 'session.cookie.lifetime' => 7200])),
        );
    }
}
