<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Session\Driver\ArraySessionHandler;
use EzPhp\Session\Flash;
use EzPhp\Session\SessionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class FlashTest
 *
 * @package Tests
 */
#[CoversClass(Flash::class)]
#[UsesClass(ArraySessionHandler::class)]
#[UsesClass(SessionException::class)]
final class FlashTest extends TestCase
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
    public function test_get_without_active_session_throws(): void
    {
        session_write_close();

        $this->expectException(SessionException::class);

        Flash::get('key');
    }

    /**
     * @return void
     */
    public function test_a_freshly_set_value_is_not_yet_readable(): void
    {
        Flash::set('message', 'saved');

        $this->assertFalse(Flash::has('message'));
        $this->assertNull(Flash::get('message'));
    }

    /**
     * @return void
     */
    public function test_a_value_becomes_readable_after_aging(): void
    {
        Flash::set('message', 'saved');
        Flash::age();

        $this->assertTrue(Flash::has('message'));
        $this->assertSame('saved', Flash::get('message'));
    }

    /**
     * @return void
     */
    public function test_a_readable_value_disappears_after_a_second_aging(): void
    {
        Flash::set('message', 'saved');
        Flash::age();
        Flash::age();

        $this->assertFalse(Flash::has('message'));
    }

    /**
     * @return void
     */
    public function test_keep_survives_another_aging_round(): void
    {
        Flash::set('message', 'saved');
        Flash::age();
        Flash::keep('message');
        Flash::age();

        $this->assertTrue(Flash::has('message'));
        $this->assertSame('saved', Flash::get('message'));
    }

    /**
     * @return void
     */
    public function test_get_returns_default_for_missing_key(): void
    {
        Flash::age();

        $this->assertSame('fallback', Flash::get('missing', 'fallback'));
    }

    /**
     * @return void
     */
    public function test_all_returns_every_readable_key(): void
    {
        Flash::set('a', 1);
        Flash::set('b', 2);
        Flash::age();

        $this->assertSame(['a' => 1, 'b' => 2], Flash::all());
    }

    /**
     * @return void
     */
    public function test_forget_removes_a_readable_key(): void
    {
        Flash::set('message', 'saved');
        Flash::age();
        Flash::forget('message');

        $this->assertFalse(Flash::has('message'));
    }
}
