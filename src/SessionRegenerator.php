<?php

declare(strict_types=1);

namespace EzPhp\Session;

/**
 * Class SessionRegenerator
 *
 * Session id regeneration helpers, built on PHP's native
 * `session_regenerate_id()`. Deliberately independent of authentication —
 * `ez-php/auth` regenerates the id itself on login/logout; this class is
 * for periodic regeneration during a long-lived session, to limit the
 * window a fixed session id stays valid.
 *
 * @package EzPhp\Session
 */
final class SessionRegenerator
{
    private const TIMESTAMP_KEY = '_session_regenerated_at';

    /**
     * Regenerate the session id unconditionally.
     *
     * @param bool $deleteOldSession
     *
     * @return void
     */
    public static function regenerate(bool $deleteOldSession = true): void
    {
        self::assertActive();

        session_regenerate_id($deleteOldSession);
        $_SESSION[self::TIMESTAMP_KEY] = time();
    }

    /**
     * Regenerate the session id only if more than `$intervalSeconds` have
     * passed since the last regeneration (or since the session was started,
     * if it has never been regenerated).
     *
     * @param int $intervalSeconds
     *
     * @return bool True if the id was regenerated.
     */
    public static function regenerateIfStale(int $intervalSeconds): bool
    {
        self::assertActive();

        $last = $_SESSION[self::TIMESTAMP_KEY] ?? null;
        $now = time();

        if (is_int($last) && $now - $last < $intervalSeconds) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::TIMESTAMP_KEY] = $now;

        return true;
    }

    /**
     * @return void
     */
    private static function assertActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new SessionException(
                'Session is not active. Start the session before regenerating its id '
                . '(e.g. add StartSessionMiddleware earlier in the pipeline).'
            );
        }
    }
}
