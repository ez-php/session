<?php

declare(strict_types=1);

namespace EzPhp\Session;

/**
 * Class Flash
 *
 * Flash data: values written during one request that survive for exactly
 * one subsequent request, then disappear. Backed directly by `$_SESSION`
 * (documented global state — see `StartSessionMiddleware`, which must run
 * first to activate the session and age the data each request).
 *
 * Storage shape: `$_SESSION['_flash']['display']` holds keys readable
 * during the *current* request; `$_SESSION['_flash']['next']` holds keys
 * queued to become readable on the *next* request. `age()` promotes
 * `next` to `display` and resets `next`, and must run once per request,
 * before any flash reads for that request.
 *
 * @package EzPhp\Session
 */
final class Flash
{
    private const SESSION_KEY = '_flash';

    /**
     * Promote next-request flash data to the current request and clear the
     * queue for values set during this request. Call once per request,
     * before any `get()`/`has()`/`all()` calls.
     *
     * @return void
     */
    public static function age(): void
    {
        self::assertActive();

        self::setDisplay(self::getNext());
        self::setNext([]);
    }

    /**
     * Queue a value to be readable on the next request.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::assertActive();

        $next = self::getNext();
        $next[$key] = $value;
        self::setNext($next);
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::assertActive();

        return self::getDisplay()[$key] ?? $default;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::assertActive();

        return array_key_exists($key, self::getDisplay());
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::assertActive();

        return self::getDisplay();
    }

    /**
     * Re-queue one or more currently-readable keys for another request.
     *
     * @param string|list<string> $keys
     *
     * @return void
     */
    public static function keep(string|array $keys): void
    {
        self::assertActive();

        foreach ((array) $keys as $key) {
            if (self::has($key)) {
                self::set($key, self::get($key));
            }
        }
    }

    /**
     * Remove a key from the current request's readable flash data.
     *
     * @param string $key
     *
     * @return void
     */
    public static function forget(string $key): void
    {
        self::assertActive();

        $display = self::getDisplay();
        unset($display[$key]);
        self::setDisplay($display);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getDisplay(): array
    {
        return self::getBagPart('display');
    }

    /**
     * @param array<string, mixed> $display
     *
     * @return void
     */
    private static function setDisplay(array $display): void
    {
        self::setBagPart('display', $display);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getNext(): array
    {
        return self::getBagPart('next');
    }

    /**
     * @param array<string, mixed> $next
     *
     * @return void
     */
    private static function setNext(array $next): void
    {
        self::setBagPart('next', $next);
    }

    /**
     * @param 'display'|'next' $part
     *
     * @return array<string, mixed>
     */
    private static function getBagPart(string $part): array
    {
        /** @var mixed $bag */
        $bag = $_SESSION[self::SESSION_KEY] ?? [];

        if (!is_array($bag)) {
            return [];
        }

        /** @var mixed $value */
        $value = $bag[$part] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param 'display'|'next'     $part
     * @param array<string, mixed> $value
     *
     * @return void
     */
    private static function setBagPart(string $part, array $value): void
    {
        /** @var mixed $bag */
        $bag = $_SESSION[self::SESSION_KEY] ?? [];

        if (!is_array($bag)) {
            $bag = [];
        }

        $bag[$part] = $value;
        $_SESSION[self::SESSION_KEY] = $bag;
    }

    /**
     * @return void
     */
    private static function assertActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new SessionException(
                'Session is not active. Start the session before using Flash '
                . '(e.g. add StartSessionMiddleware earlier in the pipeline).'
            );
        }
    }
}
