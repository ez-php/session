<?php

declare(strict_types=1);

namespace EzPhp\Session;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Session\Driver\ArraySessionHandler;
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
 * Supported drivers: `array`, `file` (default), `database`, `redis`.
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
                default => $this->makeFileHandler($config),
            };
        });
    }

    /**
     * @param ConfigInterface $config
     *
     * @return FileSessionHandler
     */
    private function makeFileHandler(ConfigInterface $config): FileSessionHandler
    {
        $path = self::configString($config, 'session.file.path', sys_get_temp_dir() . '/ez-session');

        return new FileSessionHandler($path);
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

        return new DatabaseSessionHandler($database, $table);
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
        $ttl = self::configInt($config, 'session.redis.ttl', 1440);

        return new RedisSessionHandler($host, $port, $database, $ttl);
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
