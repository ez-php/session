<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use EzPhp\Cache\CacheInterface;
use InvalidArgumentException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Class CacheSessionHandler
 *
 * Session driver on any `ez-php/cache` store — e.g. MemcachedDriver, which
 * has no session handler of its own, or DatabaseDriver/RedisDriver when the
 * application already configures its cache. Sessions are cached as strings
 * under `<prefix><id>` with the idle lifetime as TTL, so (like the Redis
 * driver) expiry is the store's job and gc() is a no-op.
 *
 * Use a shared store: ArrayDriver keeps data per process (tests only), and
 * a flush() of the cache also ends every session.
 *
 * `ez-php/cache` is a soft dependency (`require-dev` + `suggest`): this class
 * is only autoloaded when referenced.
 *
 * @package EzPhp\Session\Driver
 */
final readonly class CacheSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * @param CacheInterface $cache  Backing cache store.
     * @param int            $ttl    Entry lifetime in seconds, at least 1 — the cache's TTL 0 means
     *                               "never expire" and gc() reclaims nothing, so a session would never go.
     * @param string         $prefix Key prefix, so session keys cannot collide with cache data.
     *
     * @throws InvalidArgumentException When $ttl is below 1.
     */
    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 1440,
        private string $prefix = 'session:',
    ) {
        if ($ttl < 1) {
            throw new InvalidArgumentException("The cache session TTL must be at least 1 second, got {$ttl}.");
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
        $data = $this->cache->get($this->prefix . $id);

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
        $this->cache->set($this->prefix . $id, $data, $this->ttl);

        return true;
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $this->cache->forget($this->prefix . $id);

        return true;
    }

    /**
     * Whether a session with this id exists — under `session.use_strict_mode`
     * PHP then refuses a client-chosen unknown id (session fixation).
     *
     * @param string $id
     *
     * @return bool
     */
    public function validateId(string $id): bool
    {
        return $this->cache->has($this->prefix . $id);
    }

    /**
     * Refresh an unchanged session's TTL (lazy_write path).
     *
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    /**
     * No-op: the cache expires entries through the TTL set in write().
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
