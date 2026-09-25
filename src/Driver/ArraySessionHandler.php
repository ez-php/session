<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Class ArraySessionHandler
 *
 * In-process session driver. Data lives for the process lifetime only —
 * suitable for tests and single-process CLI usage, not for a real web
 * request/response cycle where each request is its own process.
 *
 * @package EzPhp\Session\Driver
 */
final class ArraySessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * @var array<string, array{data: string, timestamp: int}>
     */
    private array $store = [];

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
        return $this->store[$id]['data'] ?? '';
    }

    /**
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        $this->store[$id] = ['data' => $data, 'timestamp' => time()];

        return true;
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public function destroy(string $id): bool
    {
        unset($this->store[$id]);

        return true;
    }

    /**
     * Whether a session with this id exists in the store.
     *
     * Called by PHP under `session.use_strict_mode`: returning false for an
     * unknown id makes PHP issue a fresh id instead of adopting one the client
     * chose (session fixation).
     *
     * @param string $id
     *
     * @return bool
     */
    public function validateId(string $id): bool
    {
        return isset($this->store[$id]);
    }

    /**
     * Refresh an unchanged session's activity timestamp (lazy_write path).
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
     * @param int $max_lifetime
     *
     * @return int
     */
    public function gc(int $max_lifetime): int
    {
        $now = time();
        $removed = 0;

        foreach ($this->store as $id => $entry) {
            if ($now - $entry['timestamp'] > $max_lifetime) {
                unset($this->store[$id]);
                $removed++;
            }
        }

        return $removed;
    }
}
