<?php

declare(strict_types=1);

namespace EzPhp\Session\Console;

use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Console\Output;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Session\SessionServiceProvider;
use SessionHandlerInterface;

/**
 * Class SessionGcCommand
 *
 * `session:gc` — runs the configured handler's gc() once, so expired session
 * files and `sessions` rows are deleted on a schedule instead of relying on
 * PHP's probabilistic GC (which Debian/Ubuntu-packaged PHP switches off with
 * `session.gc_probability = 0`). Meant for `scheduler:run` or cron.
 *
 * The lifetime is the one the handlers enforce on read
 * (SessionServiceProvider::gcLifetime()); `--lifetime=N` overrides it. Redis and
 * cache sessions expire through their store's TTL — gc() reports 0 there.
 *
 * @package EzPhp\Session\Console
 */
final readonly class SessionGcCommand implements CommandInterface
{
    /**
     * @param SessionHandlerInterface $handler     The configured session handler.
     * @param ConfigInterface         $config      Source of session.idle_lifetime / cookie lifetime.
     * @param resource|null           $errorStream Where errors go; null = STDERR (injectable for tests).
     */
    public function __construct(
        private SessionHandlerInterface $handler,
        private ConfigInterface $config,
        private mixed $errorStream = null,
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'session:gc';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Delete expired sessions from the configured session store';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return implode("\n", [
            'Usage: ez session:gc [--lifetime=SECONDS]',
            '',
            'Options:',
            '  --lifetime=N  Idle seconds after which a session is deleted',
            '                (default: session.idle_lifetime, see the session module docs)',
        ]);
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $option = (new Input($args))->option('lifetime');

        if ($option !== '' && (!ctype_digit($option) || (int) $option < 1)) {
            Output::error('--lifetime must be a positive number of seconds.', $this->errorStream);

            return 1;
        }

        $lifetime = $option !== '' ? (int) $option : SessionServiceProvider::gcLifetime($this->config);
        $removed = $this->handler->gc($lifetime);

        if ($removed === false) {
            Output::error(sprintf('%s::gc() failed.', $this->handler::class), $this->errorStream);

            return 1;
        }

        Output::success(sprintf('Deleted %d expired session(s) idle for more than %d s.', $removed, $lifetime));

        return 0;
    }
}
