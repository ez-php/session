<?php

declare(strict_types=1);

namespace EzPhp\Session;

use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Session\Console\SessionGcCommand;
use EzPhp\Session\Driver\ArraySessionHandler;
use EzPhp\Session\Driver\CacheSessionHandler;
use EzPhp\Session\Driver\DatabaseSessionHandler;
use EzPhp\Session\Driver\FileSessionHandler;
use EzPhp\Session\Driver\RedisSessionHandler;
use SessionHandlerInterface;

/**
 * Class SessionServiceProvider
 *
 * Reads `config/session.php` and binds `SessionHandlerInterface` to the
 * driver selected by `session.driver`.
 *
 * Supported drivers: `array`, `file` (default), `database`, `redis`, `cache` (ez-php/cache store).
 *
 * With ez-php/framework installed it also binds the framework's
 * `CsrfTokenStoreInterface` to `SessionCsrfTokenStore`, unless already bound.
 *
 * `StartSessionMiddleware` is not auto-registered — add it to the global
 * middleware stack explicitly, matching how `ez-php/rate-limiter`'s
 * `ThrottleMiddleware` and `ez-php/framework`'s `CsrfMiddleware` are wired.
 *
 * @package EzPhp\Session
 */
final class SessionServiceProvider extends ServiceProvider
{
    /**
     * Registers `session:gc` (needs ez-php/console, a suggest; registered by class
     * name, so nothing is autoloaded unless the application's console runs it).
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerCommand(SessionGcCommand::class);
    }

    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(SessionHandlerInterface::class, function (): SessionHandlerInterface {
            $config = $this->app->make(ConfigInterface::class);

            $driver = self::configString($config, 'session.driver', 'file');

            return match ($driver) {
                'array' => new ArraySessionHandler(),
                'database' => $this->makeDatabaseHandler($config),
                'redis' => $this->makeRedisHandler($config),
                'cache' => $this->makeCacheHandler($config),
                default => $this->makeFileHandler($config),
            };
        });

        $this->bindCsrfTokenStore();
    }

    /**
     * Bind ez-php/framework's CsrfTokenStoreInterface to its SessionCsrfTokenStore,
     * so CsrfMiddleware resolves once sessions are set up.
     *
     * By class-name string: this module does not depend on ez-php/framework, so the
     * binding only happens when the framework is installed, and an application's
     * own binding (registered earlier) is left alone. A binding registered later
     * replaces this one as usual.
     *
     * @return void
     */
    private function bindCsrfTokenStore(): void
    {
        $interface = 'EzPhp\\Middleware\\CsrfTokenStoreInterface';
        $store = 'EzPhp\\Middleware\\SessionCsrfTokenStore';

        if (!interface_exists($interface) || !class_exists($store) || $this->app->has($interface)) {
            return;
        }

        $this->app->bind($interface, static fn (): object => new $store());
    }

    /**
     * @param ConfigInterface $config
     *
     * @return FileSessionHandler
     */
    private function makeFileHandler(ConfigInterface $config): FileSessionHandler
    {
        $path = self::configString($config, 'session.file.path', sys_get_temp_dir() . '/ez-session');

        return new FileSessionHandler($path, self::idleLifetime($config));
    }

    /**
     * @param ConfigInterface $config
     *
     * @return DatabaseSessionHandler
     */
    private function makeDatabaseHandler(ConfigInterface $config): DatabaseSessionHandler
    {
        /** @var DatabaseInterface $database */
        $database = $this->app->make(DatabaseInterface::class);
        $table = self::configString($config, 'session.database.table', 'sessions');

        return new DatabaseSessionHandler($database, $table, self::idleLifetime($config));
    }

    /**
     * @param ConfigInterface $config
     *
     * @return RedisSessionHandler
     */
    private function makeRedisHandler(ConfigInterface $config): RedisSessionHandler
    {
        $host = self::configString($config, 'session.redis.host', '127.0.0.1');
        $port = self::configInt($config, 'session.redis.port', 6379);
        $database = self::configInt($config, 'session.redis.database', 0);
        $ttl = self::configInt($config, 'session.redis.ttl', self::storeTtl($config));

        return new RedisSessionHandler($host, $port, $database, $ttl);
    }

    /**
     * Sessions on the application's ez-php/cache store (`CacheInterface` from the container).
     *
     * @param ConfigInterface $config
     *
     * @return CacheSessionHandler
     */
    private function makeCacheHandler(ConfigInterface $config): CacheSessionHandler
    {
        /** @var CacheInterface $cache */
        $cache = $this->app->make(CacheInterface::class);
        $prefix = self::configString($config, 'session.cache.prefix', 'session:');

        return new CacheSessionHandler($cache, self::storeTtl($config), $prefix);
    }

    /**
     * Idle lifetime in seconds (`session.idle_lifetime`); 0 disables the handlers' idle
     * check. Unset, it follows php.ini's `session.gc_maxlifetime` (PHP default 1440), so a
     * deployment that already tuned that setting keeps its lifetime.
     *
     * @param ConfigInterface $config
     *
     * @return int
     */
    private static function idleLifetime(ConfigInterface $config): int
    {
        return self::configInt($config, 'session.idle_lifetime', StartSessionMiddleware::iniGcMaxLifetime());
    }

    /**
     * The idle lifetime `session:gc` deletes by: the one the file/database handlers
     * enforce on read, or — with `session.idle_lifetime = 0` — the longer of the
     * cookie lifetime and php.ini's `session.gc_maxlifetime` (the store TTL).
     *
     * @param ConfigInterface $config
     *
     * @return int
     */
    public static function gcLifetime(ConfigInterface $config): int
    {
        return self::storeTtl($config);
    }

    /**
     * Entry TTL for the stores that expire sessions themselves (redis, cache): the idle
     * lifetime, or — when `session.idle_lifetime` is 0 (idle check disabled) — the longer of
     * the cookie lifetime and php.ini's `session.gc_maxlifetime`. A TTL of 0 would be "never
     * expire" there, and nothing else ever deletes those entries.
     *
     * @param ConfigInterface $config
     *
     * @return int At least 1.
     */
    private static function storeTtl(ConfigInterface $config): int
    {
        $idle = self::idleLifetime($config);

        if ($idle > 0) {
            return $idle;
        }

        return max(1, self::configInt($config, 'session.cookie.lifetime', 0), StartSessionMiddleware::iniGcMaxLifetime());
    }

    /**
     * Read a string config value, falling back to $default when it is missing or not a string.
     *
     * @param ConfigInterface $config
     * @param string          $key
     * @param string          $default
     *
     * @return string
     */
    private static function configString(ConfigInterface $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Read an int config value (int or numeric string, e.g. an uncast getenv() result),
     * falling back to $default otherwise.
     *
     * @param ConfigInterface $config
     * @param string          $key
     * @param int             $default
     *
     * @return int
     */
    private static function configInt(ConfigInterface $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }
}
