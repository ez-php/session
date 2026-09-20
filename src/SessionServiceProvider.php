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

            /** @var string $driver */
            $driver = $config->get('session.driver', 'file');

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
        /** @var string $path */
        $path = $config->get('session.file.path', sys_get_temp_dir() . '/ez-session');

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
        /** @var string $table */
        $table = $config->get('session.database.table', 'sessions');

        return new DatabaseSessionHandler($database, $table);
    }

    /**
     * @param ConfigInterface $config
     *
     * @return RedisSessionHandler
     */
    private function makeRedisHandler(ConfigInterface $config): RedisSessionHandler
    {
        /** @var string $host */
        $host = $config->get('session.redis.host', '127.0.0.1');
        /** @var int $port */
        $port = $config->get('session.redis.port', 6379);
        /** @var int $database */
        $database = $config->get('session.redis.database', 0);
        /** @var int $ttl */
        $ttl = $config->get('session.redis.ttl', 1440);

        return new RedisSessionHandler($host, $port, $database, $ttl);
    }
}
