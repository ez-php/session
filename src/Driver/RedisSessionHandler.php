<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use EzPhp\Session\SessionException;
use Redis;
use SessionHandlerInterface;

/**
 * Class RedisSessionHandler
 *
 * Redis-backed session driver using the PHP "ext-redis" extension.
 * Sessions are stored under `session:<id>` with a native Redis TTL, so
 * expiry needs no separate gc() sweep.
 *
 * @package EzPhp\Session\Driver
 */
final class RedisSessionHandler implements SessionHandlerInterface
{
    private const KEY_PREFIX = 'session:';

    private readonly Redis $redis;

    /**
     * RedisSessionHandler Constructor
     *
     * @param string $host
     * @param int    $port
     * @param int    $database
     * @param int    $ttl Seconds a session key lives without being rewritten.
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $database = 0,
        private readonly int $ttl = 1440,
    ) {
        if (!extension_loaded('redis')) {
            throw new SessionException('The "redis" PHP extension is required for the Redis session driver.');
        }

        $this->redis = new Redis();

        try {
            $connected = @$this->redis->connect($host, $port);
        } catch (\RedisException $e) {
            throw new SessionException("Redis connection failed: {$e->getMessage()}", previous: $e);
        }

        if (!$connected) {
            throw new SessionException("Redis connection failed: could not connect to {$host}:{$port}.");
        }

        if ($database !== 0) {
            $this->redis->select($database);
        }
    }

    /**
     * @param string $path
     * @param string $name
     *
     * @return bool
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * @param string $id
     *
     * @return string
     */
    public function read(string $id): string
    {
        $data = $this->redis->get(self::KEY_PREFIX . $id);

        return is_string($data) ? $data : '';
    }

    /**
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        return (bool) $this->redis->setex(self::KEY_PREFIX . $id, $this->ttl, $data);
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $this->redis->del(self::KEY_PREFIX . $id);

        return true;
    }

    /**
     * No-op: Redis expires keys natively via the TTL set in write().
     *
     * @param int $max_lifetime
     *
     * @return int
     */
    public function gc(int $max_lifetime): int
    {
        return 0;
    }
}
