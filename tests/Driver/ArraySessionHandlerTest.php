<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Session\Driver\ArraySessionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class ArraySessionHandlerTest
 *
 * @package Tests\Driver
 */
#[CoversClass(ArraySessionHandler::class)]
final class ArraySessionHandlerTest extends TestCase
{
    private ArraySessionHandler $handler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->handler = new ArraySessionHandler();
    }

    /**
     * @return void
     */
    public function test_open_and_close_return_true(): void
    {
        $this->assertTrue($this->handler->open('/tmp', 'PHPSESSID'));
        $this->assertTrue($this->handler->close());
    }

    /**
     * @return void
     */
    public function test_read_returns_empty_string_for_missing_id(): void
    {
        $this->assertSame('', $this->handler->read('missing'));
    }

    /**
     * @return void
     */
    public function test_write_then_read_round_trips(): void
    {
        $this->handler->write('abc', 'payload-data');

        $this->assertSame('payload-data', $this->handler->read('abc'));
    }

    /**
     * @return void
     */
    public function test_destroy_removes_the_entry(): void
    {
        $this->handler->write('abc', 'payload-data');
        $this->handler->destroy('abc');

        $this->assertSame('', $this->handler->read('abc'));
    }

    /**
     * @return void
     */
    public function test_destroy_on_missing_id_is_a_no_op(): void
    {
        $this->assertTrue($this->handler->destroy('missing'));
    }

    /**
     * @return void
     */
    public function test_gc_removes_entries_older_than_max_lifetime(): void
    {
        $this->handler->write('stale', 'data');

        $removed = $this->handler->gc(-1);

        $this->assertSame(1, $removed);
        $this->assertSame('', $this->handler->read('stale'));
    }

    /**
     * @return void
     */
    public function test_gc_keeps_entries_within_max_lifetime(): void
    {
        $this->handler->write('fresh', 'data');

        $removed = $this->handler->gc(1_000_000);

        $this->assertSame(0, $removed);
        $this->assertSame('data', $this->handler->read('fresh'));
    }

    /**
     * validateId() backs session.use_strict_mode (session-fixation protection).
     *
     * @return void
     */
    public function testValidateIdReflectsWhetherTheSessionExists(): void
    {
        $id = 'sess-validate';

        $this->assertFalse($this->handler->validateId($id));

        $this->handler->write($id, 'payload');
        $this->assertTrue($this->handler->validateId($id));

        $this->handler->destroy($id);
        $this->assertFalse($this->handler->validateId($id));
    }

    /**
     * @return void
     */
    public function testUpdateTimestampKeepsTheSessionData(): void
    {
        $id = 'sess-validate';
        $this->handler->write($id, 'payload');

        $this->assertTrue($this->handler->updateTimestamp($id, 'payload'));
        $this->assertSame('payload', $this->handler->read($id));

        $this->handler->destroy($id);
    }
}
