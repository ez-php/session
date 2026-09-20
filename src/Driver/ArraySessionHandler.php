<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use SessionHandlerInterface;

/**
 * Class ArraySessionHandler
 *
 * In-process session driver. Data lives for the process lifetime only —
 * suitable for tests and single-process CLI usage, not for a real web
 * request/response cycle where each request is its own process.
 *
 * @package EzPhp\Session\Driver
 */
final class ArraySessionHandler implements SessionHandlerInterface
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
