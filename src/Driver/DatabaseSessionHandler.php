<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Session\SessionException;
use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Class DatabaseSessionHandler
 *
 * PDO-backed session driver via `ez-php/contracts`' `DatabaseInterface`.
 * Sessions are stored in a `sessions` table, created automatically
 * (`CREATE TABLE IF NOT EXISTS`) with driver-aware DDL: MySQL, and a portable
 * form for SQLite and PostgreSQL. `write()` is a single driver-aware upsert,
 * so it neither depends on affected-row counts (MySQL reports 0 for an UPDATE
 * that changes nothing) nor races between two first writes of the same id.
 * A session whose `last_activity` is older than `$lifetime` seconds is treated
 * as gone by read() and validateId(), independent of PHP's probabilistic gc().
 *
 * @package EzPhp\Session\Driver
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * DatabaseSessionHandler Constructor
     *
     * @param DatabaseInterface $database
     * @param string            $table    Session table; interpolated into SQL, so limited to `[A-Za-z0-9_]`.
     * @param int               $lifetime Idle lifetime in seconds; a session not written for longer is expired.
     *                                    0 (or less) disables the check and leaves expiry to gc().
     *
     * @throws SessionException When the table name contains anything but letters, digits or underscores.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly string $table = 'sessions',
        private readonly int $lifetime = 1440,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new SessionException("Invalid session table name '{$table}': only [A-Za-z0-9_] are allowed.");
        }

        $this->createTableIfNeeded();
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
        $rows = $this->database->query(
            "SELECT payload, last_activity FROM {$this->table} WHERE id = :id",
            ['id' => $id],
        );

        if ($rows === []) {
            return '';
        }

        $lastActivity = $rows[0]['last_activity'] ?? null;

        if ($this->lifetime > 0 && is_numeric($lastActivity) && (int) $lastActivity < time() - $this->lifetime) {
            return '';
        }

        $payload = $rows[0]['payload'] ?? '';

        return is_string($payload) ? $payload : '';
    }

    /**
     * @param string $id
     * @param string $data
     *
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        $sql = "INSERT INTO {$this->table} (id, payload, last_activity) VALUES (:id, :payload, :now)";
        $now = time();
        $bindings = ['id' => $id, 'payload' => $data, 'now' => $now];

        if ($this->database->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Separate placeholders: VALUES() in ON DUPLICATE KEY is deprecated since MySQL 8.0.20.
            $sql .= ' ON DUPLICATE KEY UPDATE payload = :update_payload, last_activity = :update_now';
            $bindings += ['update_payload' => $data, 'update_now' => $now];
        } else {
            // SQLite 3.24+ and PostgreSQL 9.5+.
            $sql .= ' ON CONFLICT (id) DO UPDATE SET payload = excluded.payload, last_activity = excluded.last_activity';
        }

        $this->database->execute($sql, $bindings);

        return true;
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $this->database->execute("DELETE FROM {$this->table} WHERE id = :id", ['id' => $id]);

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
        if ($this->lifetime <= 0) {
            return $this->database->query(
                "SELECT 1 AS present FROM {$this->table} WHERE id = :id",
                ['id' => $id],
            ) !== [];
        }

        return $this->database->query(
            "SELECT 1 AS present FROM {$this->table} WHERE id = :id AND last_activity >= :threshold",
            ['id' => $id, 'threshold' => time() - $this->lifetime],
        ) !== [];
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
        return $this->database->execute(
            "DELETE FROM {$this->table} WHERE last_activity < :threshold",
            ['threshold' => time() - $max_lifetime],
        );
    }

    /**
     * Create the sessions table if it does not already exist.
     *
     * Uses driver-aware DDL: MySQL's own column types and inline index, and a
     * portable form (TEXT/BIGINT plus a separate CREATE INDEX) for SQLite and
     * PostgreSQL. Runs on every construction — one cheap `IF NOT EXISTS`
     * statement pair per request that uses the database driver — so the
     * driver works without a migration step (see the module's CLAUDE.md).
     *
     * @return void
     */
    private function createTableIfNeeded(): void
    {
        $driver = $this->database->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'mysql') {
            $this->database->getPdo()->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id            TEXT   PRIMARY KEY,
                    payload       TEXT   NOT NULL,
                    last_activity BIGINT NOT NULL
                )"
            );
            // Same index as the MySQL DDL, for gc() and the idle-lifetime check. SQLite
            // and PostgreSQL index names are global to the database/schema, so it is
            // named after the table.
            $this->database->getPdo()->exec(
                "CREATE INDEX IF NOT EXISTS {$this->table}_last_activity_idx ON {$this->table} (last_activity)"
            );

            return;
        }

        $this->database->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id            VARCHAR(128) PRIMARY KEY,
                payload       LONGTEXT     NOT NULL,
                last_activity INT UNSIGNED NOT NULL,
                INDEX sessions_last_activity_idx (last_activity)
            )"
        );
    }
}
