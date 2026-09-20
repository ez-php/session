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
            session_start();
        }

        Flash::age();

        /** @var int $regenerateInterval */
        $regenerateInterval = $this->config->get('session.regenerate_interval', 0);

        if ($regenerateInterval > 0) {
            SessionRegenerator::regenerateIfStale($regenerateInterval);
        }

        /** @var ResponseInterface */
        return $next($request);
    }
}
