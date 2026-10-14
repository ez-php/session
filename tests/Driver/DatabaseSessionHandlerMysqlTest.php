<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Session\Driver\DatabaseSessionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\SessionPdoDatabase;
use Tests\TestCase;

/**
 * Class DatabaseSessionHandlerMysqlTest
 *
 * Runs DatabaseSessionHandler against MySQL, whose affected-row count differs from
 * SQLite's: an UPDATE that changes nothing reports 0 rows on MySQL. Skipped when
 * DB_HOST is unset (same convention as ez-php/orm's and ez-php/auth's MySQL tests).
 *
 * @package Tests\Driver
 * @uses \Tests\SessionPdoDatabase
 */
#[CoversClass(DatabaseSessionHandler::class)]
final class DatabaseSessionHandlerMysqlTest extends TestCase
{
    private const string TABLE = 'session_handler_mysql_test';

    private ?SessionPdoDatabase $database = null;

    private DatabaseSessionHandler $handler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $host = (string) getenv('DB_HOST');

        if ($host === '') {
            $this->markTestSkipped('MySQL not available — set DB_HOST to run this test.');
        }

        $port = (string) (getenv('DB_PORT') ?: '3306');
        $name = (string) (getenv('DB_TESTING_DATABASE') ?: 'ez-php_testing');

        $this->database = new SessionPdoDatabase(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
        );
        $this->database->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->handler = new DatabaseSessionHandler($this->database, self::TABLE);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->database?->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /**
     * Two writes of an unchanged payload within one second leave the row untouched,
     * so MySQL reports 0 affected rows — this must not turn into a duplicate INSERT.
     *
     * @return void
     */
    public function test_writing_the_same_payload_twice_in_one_second_succeeds(): void
    {
        self::assertTrue($this->handler->write('abc', 'payload'));
        self::assertTrue($this->handler->write('abc', 'payload'));

        self::assertSame('payload', $this->handler->read('abc'));
    }

    /**
     * @return void
     */
    public function test_write_inserts_then_updates(): void
    {
        $this->handler->write('abc', 'first');
        $this->handler->write('abc', 'second');

        self::assertSame('second', $this->handler->read('abc'));
        self::assertTrue($this->handler->updateTimestamp('abc', 'second'));
    }
}
