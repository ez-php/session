<?php

declare(strict_types=1);

namespace EzPhp\Session;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\ResponseInterface;
use SessionHandlerInterface;

/**
 * Class StartSessionMiddleware
 *
 * Registers the configured `SessionHandlerInterface` driver and starts the
 * PHP session, then ages flash data for the request. Must run before any
 * middleware or handler that reads `$_SESSION`, `Flash`, or
 * `SessionRegenerator` — including `ez-php/auth` and
 * `SessionCsrfTokenStore` in `ez-php/framework`.
 *
 * Registration is a no-op if a session is already active, so this
 * middleware is safe to add even when something earlier in the pipeline
 * (or a test harness) already started one.
 *
 * The session is started with hardened cookie and id settings instead of
 * PHP's permissive defaults (see options()): `HttpOnly`, `SameSite=Lax`,
 * `Secure` on HTTPS requests, and `use_strict_mode` so an id the client
 * chose is never adopted (session fixation) — the bundled handlers implement
 * `validateId()`, which strict mode needs to work with a custom handler.
 *
 * @package EzPhp\Session
 */
final class StartSessionMiddleware implements MiddlewareInterface
{
    /**
     * StartSessionMiddleware Constructor
     *
     * @param SessionHandlerInterface $handler
     * @param ConfigInterface         $config
     */
    public function __construct(
        private readonly SessionHandlerInterface $handler,
        private readonly ConfigInterface $config,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return ResponseInterface
     */
    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_save_handler($this->handler, true);
            session_start($this->options($request));
        }

        Flash::age();

        $regenerateInterval = $this->int('session.regenerate_interval', 0);

        if ($regenerateInterval > 0) {
            SessionRegenerator::regenerateIfStale($regenerateInterval);
        }

        /** @var ResponseInterface */
        return $next($request);
    }

    /**
     * Build the session_start() options from `session.cookie.*` / `session.strict_mode`.
     *
     * Defaults: HttpOnly on, SameSite=Lax, Secure auto-detected from the
     * request (`HTTPS` server variable; set `session.cookie.secure` to true
     * explicitly when TLS terminates at a proxy), strict mode on, cookie
     * lifetime 0 (browser session), path `/`.
     *
     * @param RequestInterface $request
     *
     * @return array<string, bool|int|string>
     */
    public function options(RequestInterface $request): array
    {
        $secure = $this->config->get('session.cookie.secure');

        if (!is_bool($secure)) {
            $https = $request->server('HTTPS');
            $secure = is_string($https) && $https !== '' && strtolower($https) !== 'off';
        }

        $options = [
            'use_strict_mode' => (bool) $this->config->get('session.strict_mode', true),
            'use_only_cookies' => true,
            'cookie_httponly' => (bool) $this->config->get('session.cookie.httponly', true),
            'cookie_secure' => $secure,
            'cookie_samesite' => $this->string('session.cookie.samesite', 'Lax'),
            'cookie_lifetime' => $this->int('session.cookie.lifetime', 0),
            'cookie_path' => $this->string('session.cookie.path', '/'),
            'cookie_domain' => $this->string('session.cookie.domain', ''),
        ];

        $name = $this->string('session.cookie.name', '');

        if ($name !== '') {
            $options['name'] = $name;
        }

        return $options;
    }

    /**
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param string $key
     * @param int    $default
     *
     * @return int
     */
    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
