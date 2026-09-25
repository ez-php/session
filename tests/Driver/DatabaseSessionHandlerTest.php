<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Session\Driver\DatabaseSessionHandler;
use EzPhp\Session\SessionException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\SessionPdoDatabase;
use Tests\TestCase;

/**
 * Class DatabaseSessionHandlerTest
 *
 * Runs against SQLite in-memory via `Tests\SessionPdoDatabase` — no MySQL/Docker required.
 *
 * @package Tests\Driver
 * @uses \Tests\SessionPdoDatabase
 */
#[CoversClass(DatabaseSessionHandler::class)]
final class DatabaseSessionHandlerTest extends TestCase
{
    private SessionPdoDatabase $database;

    private DatabaseSessionHandler $handler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->database = new SessionPdoDatabase('sqlite::memory:');
        $this->handler = new DatabaseSessionHandler($this->database);
    }

    /**
     * @return void
     */
    public function test_rejects_unsafe_table_name(): void
    {
        $this->expectException(SessionException::class);

        new DatabaseSessionHandler($this->database, 'sessions; DROP TABLE users');
    }

    /**
     * @return void
     */
    public function test_accepts_custom_safe_table_name(): void
    {
        $handler = new DatabaseSessionHandler($this->database, 'app_sessions_2');
        $handler->write('abc', 'payload');

        self::assertSame('payload', $handler->read('abc'));
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
    public function test_write_inserts_a_new_row(): void
    {
        $this->handler->write('abc', 'payload-data');

        $this->assertSame('payload-data', $this->handler->read('abc'));
    }

    /**
     * @return void
     */
    public function test_write_updates_an_existing_row(): void
    {
        $this->handler->write('abc', 'first');
        $this->handler->write('abc', 'second');

        $this->assertSame('second', $this->handler->read('abc'));

        $rows = $this->database->query('SELECT COUNT(*) AS count FROM sessions');
        /** @var int|string $count */
        $count = $rows[0]['count'];
        $this->assertSame(1, (int) $count);
    }

    /**
     * @return void
     */
    public function test_destroy_removes_the_row(): void
    {
        $this->handler->write('abc', 'payload-data');
        $this->handler->destroy('abc');

        $this->assertSame('', $this->handler->read('abc'));
    }

    /**
     * @return void
     */
    public function test_gc_removes_stale_rows(): void
    {
        $this->handler->write('stale', 'data');
        $this->database->execute(
            'UPDATE sessions SET last_activity = :ts WHERE id = :id',
            ['ts' => time() - 1000, 'id' => 'stale'],
        );

        $removed = $this->handler->gc(1);

        $this->assertSame(1, $removed);
        $this->assertSame('', $this->handler->read('stale'));
    }

    /**
     * @return void
     */
    public function test_gc_keeps_fresh_rows(): void
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
