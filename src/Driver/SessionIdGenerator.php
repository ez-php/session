<?php

declare(strict_types=1);

namespace EzPhp\Session\Driver;

/**
 * Class SessionIdGenerator
 *
 * Generates session ids for the bundled handlers' `SessionIdInterface::create_sid()`.
 *
 * Produces 32 lowercase hex characters from 16 bytes of `random_bytes()` —
 * the same 128 bits PHP's own generator yields with its defaults
 * (`session.sid_length=32`, `session.sid_bits_per_character=4`), and inside the
 * `[a-zA-Z0-9,-]` charset every handler accepts. Generated here rather than via
 * `session_create_id()` so the format stays fixed and independent of the
 * `session.sid_length`/`session.sid_bits_per_character` INI settings, both
 * deprecated since PHP 8.4.
 *
 * @package EzPhp\Session\Driver
 */
final class SessionIdGenerator
{
    /**
     * Return a fresh, cryptographically random session id.
     *
     * @return string
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
