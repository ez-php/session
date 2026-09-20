<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Session\Driver\ArraySessionHandler;
use EzPhp\Session\SessionException;
use EzPhp\Session\SessionRegenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class SessionRegeneratorTest
 *
 * @package Tests
 */
#[CoversClass(SessionRegenerator::class)]
#[UsesClass(ArraySessionHandler::class)]
#[UsesClass(SessionException::class)]
final class SessionRegeneratorTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        session_set_save_handler(new ArraySessionHandler(), true);
        session_start();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_write_close();
        }
    }

    /**
     * @return void
     */
    public function test_regenerate_without_active_session_throws(): void
    {
        session_write_close();

        $this->expectException(SessionException::class);

        SessionRegenerator::regenerate();
    }

    /**
     * @return void
     */
    public function test_regenerate_changes_the_session_id(): void
    {
        $before = session_id();

        SessionRegenerator::regenerate();

        $this->assertNotSame($before, session_id());
    }

    /**
     * @return void
     */
    public function test_regenerate_if_stale_regenerates_when_never_regenerated(): void
    {
        $regenerated = SessionRegenerator::regenerateIfStale(60);

        $this->assertTrue($regenerated);
    }

    /**
     * @return void
     */
    public function test_regenerate_if_stale_skips_when_within_interval(): void
    {
        SessionRegenerator::regenerateIfStale(60);
        $idAfterFirst = session_id();

        $regenerated = SessionRegenerator::regenerateIfStale(60);

        $this->assertFalse($regenerated);
        $this->assertSame($idAfterFirst, session_id());
    }

    /**
     * @return void
     */
    public function test_regenerate_if_stale_regenerates_once_interval_elapsed(): void
    {
        SessionRegenerator::regenerateIfStale(60);

        $regenerated = SessionRegenerator::regenerateIfStale(-1);

        $this->assertTrue($regenerated);
    }
}
