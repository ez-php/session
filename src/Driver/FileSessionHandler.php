<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use EzPhp\Session\SessionException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Class FileSessionHandler
 *
 * Filesystem session driver. Each session is stored as a file named
 * `sess_<id>` inside the configured directory. A session whose file has not
 * been written for longer than `$lifetime` seconds is treated as gone by
 * read() and validateId(), independent of PHP's probabilistic gc().
 *
 * @package EzPhp\Session\Driver
 */
final class FileSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * FileSessionHandler Constructor
     *
     * @param string $directory
     * @param int    $lifetime  Idle lifetime in seconds; a session not written for longer is expired.
     *                          0 (or less) disables the check and leaves expiry to gc().
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $lifetime = 1440,
    ) {
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
        $path = $this->pathFor($id);

        if ($this->isExpired($path)) {
            self::quietly(static fn (): bool => unlink($path));

            return '';
        }

        $contents = self::quietly(static fn (): string|false => file_get_contents($path));

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
        if (preg_match('/^[a-zA-Z0-9,\-]+$/', $id) !== 1) {
            return false;
        }

        $path = $this->pathFor($id);

        return is_file($path) && !$this->isExpired($path);
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
     * Whether the session file exists but has been idle longer than the lifetime.
     *
     * @param string $path
     *
     * @return bool
     */
    private function isExpired(string $path): bool
    {
        if ($this->lifetime <= 0) {
            return false;
        }

        clearstatcache(true, $path);
        $mtime = self::quietly(static fn (): int|false => filemtime($path));

        return $mtime !== false && time() - $mtime > $this->lifetime;
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

    /**
     * Run a call whose PHP warning is expected and handled through its return value,
     * without the `@` operator.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private static function quietly(callable $fn)
    {
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
