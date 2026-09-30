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

    /**
     * With ez-php/framework installed, the provider binds the framework's
     * CsrfTokenStoreInterface to SessionCsrfTokenStore so CsrfMiddleware resolves.
     *
     * @return void
     */
    public function test_register_binds_the_csrf_token_store_when_the_framework_is_installed(): void
    {
        $interface = self::frameworkClass('CsrfTokenStoreInterface');

        if (!interface_exists($interface)) {
            self::markTestSkipped('ez-php/framework is not installed.');
        }

        $container = new FakeContainer(new FakeConfig());
        (new SessionServiceProvider($container))->register();

        self::assertInstanceOf(self::frameworkClass('SessionCsrfTokenStore'), $container->make($interface));
    }

    /**
     * @return void
     */
    public function test_register_keeps_an_existing_csrf_token_store_binding(): void
    {
        $interface = self::frameworkClass('CsrfTokenStoreInterface');

        if (!interface_exists($interface)) {
            self::markTestSkipped('ez-php/framework is not installed.');
        }

        $own = (new \ReflectionClass(self::frameworkClass('SessionCsrfTokenStore')))->newInstance();
        $container = new FakeContainer(new FakeConfig());
        $container->instance($interface, $own);

        (new SessionServiceProvider($container))->register();

        self::assertSame($own, $container->make($interface));
    }

    /**
     * A class of ez-php/framework, by name — this module does not depend on the
     * framework, so its classes may be absent (the tests then skip).
     *
     * @param string $short
     *
     * @return class-string
     */
    private static function frameworkClass(string $short): string
    {
        /** @var class-string */
        return 'EzPhp\\Middleware\\' . $short;
    }
}
