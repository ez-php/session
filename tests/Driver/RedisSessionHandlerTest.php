<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Session\Driver\RedisSessionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Throwable;

/**
 * Class RedisSessionHandlerTest
 *
 * Integration tests for the Redis session driver. All tests are skipped
 * when the "redis" PHP extension is not loaded or the configured Redis
 * server is unreachable.
 *
 * @package Tests\Driver
 */
#[CoversClass(RedisSessionHandler::class)]
final class RedisSessionHandlerTest extends TestCase
{
    private const string HOST = 'redis';
    private const int PORT = 6379;
    private const int DB = 3; // dedicated database to avoid colliding with other modules' Redis tests

    private RedisSessionHandler $handler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The "redis" PHP extension is not loaded.');
        }

        try {
            $this->handler = new RedisSessionHandler(self::HOST, self::PORT, self::DB, 1440);
            $this->handler->destroy('abc');
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis server not reachable: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->handler)) {
            $this->handler->destroy('abc');
        }
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
    public function test_destroy_removes_the_key(): void
    {
        $this->handler->write('abc', 'payload-data');
        $this->handler->destroy('abc');

        $this->assertSame('', $this->handler->read('abc'));
    }

    /**
     * @return void
     */
    public function test_gc_is_a_no_op(): void
    {
        $this->assertSame(0, $this->handler->gc(1440));
    }
}
