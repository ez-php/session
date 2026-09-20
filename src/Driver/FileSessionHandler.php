<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use EzPhp\Session\SessionException;
use SessionHandlerInterface;

/**
 * Class FileSessionHandler
 *
 * Filesystem session driver. Each session is stored as a file named
 * `sess_<id>` inside the configured directory.
 *
 * @package EzPhp\Session\Driver
 */
final class FileSessionHandler implements SessionHandlerInterface
{
    /**
     * FileSessionHandler Constructor
     *
     * @param string $directory
     */
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param string $path
     * @param string $name
     *
     * @return bool
     */
    public function open(string $path, string $name): bool
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new SessionException("Cannot create session directory: {$this->directory}");
        }

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
        $contents = @file_get_contents($this->pathFor($id));

        return is_string($contents) ? $contents : '';
    }

    /**
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        return file_put_contents($this->pathFor($id), $data, LOCK_EX) !== false;
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $path = $this->pathFor($id);

        if (is_file($path)) {
            unlink($path);
        }

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

        foreach (glob($this->directory . '/sess_*') ?: [] as $file) {
            $mtime = filemtime($file);

            if ($mtime !== false && $now - $mtime > $max_lifetime) {
                unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Resolve the storage path for a session id.
     *
     * Rejects ids outside PHP's own session-id charset (letters, digits,
     * comma, hyphen) to rule out path traversal via a crafted session
     * cookie, in case a non-default `session.sid_bits_per_character` or a
     * custom id generator ever widens what reaches the handler.
     *
     * @param string $id
     *
     * @return string
     */
    private function pathFor(string $id): string
    {
        if (preg_match('/^[a-zA-Z0-9,\-]+$/', $id) !== 1) {
            throw new SessionException("Invalid session id: {$id}");
        }

        return $this->directory . '/sess_' . $id;
    }
}
