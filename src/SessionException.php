<?php

declare(strict_types=1);

namespace EzPhp\Session;

use RuntimeException;

/**
 * Class SessionException
 *
 * Thrown for all session handling errors: an unavailable driver extension,
 * an invalid session id, or an operation attempted while the session is
 * not active.
 *
 * @package EzPhp\Session
 */
final class SessionException extends RuntimeException
{
}
