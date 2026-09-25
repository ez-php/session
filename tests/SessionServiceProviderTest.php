<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Session\Driver\ArraySessionHandler;
use EzPhp\Session\Driver\DatabaseSessionHandler;
use EzPhp\Session\Driver\FileSessionHandler;
use EzPhp\Session\SessionServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use SessionHandlerInterface;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * Class SessionServiceProviderTest
 *
 * @package Tests
 * @uses \Tests\Support\FakeConfig
 * @uses \Tests\Support\FakeContainer
 * @uses \Tests\SessionPdoDatabase
 */
#[CoversClass(SessionServiceProvider::class)]
#[UsesClass(ArraySessionHandler::class)]
#[UsesClass(FileSessionHandler::class)]
#[UsesClass(DatabaseSessionHandler::class)]
final class SessionServiceProviderTest extends TestCase
{
    /**
     * @return void
     */
    public function test_register_binds_array_driver_when_configured(): void
    {
        $container = new FakeContainer(new FakeConfig(['session.driver' => 'array']));
        $provider = new SessionServiceProvider($container);

        $provider->register();

        $this->assertInstanceOf(ArraySessionHandler::class, $container->make(SessionHandlerInterface::class));
    }

    /**
     * @return void
     */
    public function test_register_binds_file_driver_by_default(): void
    {
        $container = new FakeContainer(new FakeConfig());
        $provider = new SessionServiceProvider($container);

        $provider->register();

        $this->assertInstanceOf(FileSessionHandler::class, $container->make(SessionHandlerInterface::class));
    }

    /**
     * @return void
     */
    public function test_register_binds_database_driver_when_configured(): void
    {
        $container = new FakeContainer(new FakeConfig(['session.driver' => 'database']));
        $container->instance(DatabaseInterface::class, new SessionPdoDatabase('sqlite::memory:'));
        $provider = new SessionServiceProvider($container);

        $provider->register();

        $this->assertInstanceOf(DatabaseSessionHandler::class, $container->make(SessionHandlerInterface::class));
    }

    /**
     * @return void
     */
    public function test_register_falls_back_to_file_driver_for_unknown_value(): void
    {
        $container = new FakeContainer(new FakeConfig(['session.driver' => 'nonsense']));
        $provider = new SessionServiceProvider($container);

        $provider->register();

        $this->assertInstanceOf(FileSessionHandler::class, $container->make(SessionHandlerInterface::class));
    }

    /**
     * Wrong-typed config values (e.g. an application config that forgot to cast)
     * fall back to the defaults instead of raising a TypeError.
     *
     * @return void
     */
    public function test_wrong_typed_config_values_fall_back_to_defaults(): void
    {
        $container = new FakeContainer(new FakeConfig([
            'session.driver' => 42,
            'session.file.path' => ['not', 'a', 'path'],
        ]));
        $provider = new SessionServiceProvider($container);

        $provider->register();

        $this->assertInstanceOf(FileSessionHandler::class, $container->make(SessionHandlerInterface::class));
    }
}
