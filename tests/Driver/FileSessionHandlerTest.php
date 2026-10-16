<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Session\Driver\FileSessionHandler;
use EzPhp\Session\SessionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class FileSessionHandlerTest
 *
 * @package Tests\Driver
 */
#[CoversClass(FileSessionHandler::class)]
#[UsesClass(SessionException::class)]
final class FileSessionHandlerTest extends TestCase
{
    private string $directory;

    private FileSessionHandler $handler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ez-session-test-' . uniqid();
        $this->handler = new FileSessionHandler($this->directory);
        $this->handler->open($this->directory, 'PHPSESSID');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * @return void
     */
    public function test_open_creates_the_directory(): void
    {
        $this->assertDirectoryExists($this->directory);
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
        $this->handler->write('abc123', 'payload-data');

        $this->assertSame('payload-data', $this->handler->read('abc123'));
    }

    /**
     * @return void
     */
    public function test_destroy_removes_the_file(): void
    {
        $this->handler->write('abc123', 'payload-data');
        $this->handler->destroy('abc123');

        $this->assertSame('', $this->handler->read('abc123'));
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
    public function test_read_rejects_an_id_with_path_traversal(): void
    {
        $this->expectException(SessionException::class);

        $this->handler->read('../../etc/passwd');
    }

    /**
     * @return void
     */
    public function test_write_rejects_an_id_with_path_traversal(): void
    {
        $this->expectException(SessionException::class);

        $this->handler->write('../evil', 'data');
    }

    /**
     * @return void
     */
    public function test_gc_removes_files_older_than_max_lifetime(): void
    {
        $this->handler->write('stale', 'data');
        touch($this->directory . '/sess_stale', time() - 1000);

        $removed = $this->handler->gc(1);

        $this->assertSame(1, $removed);
        $this->assertSame('', $this->handler->read('stale'));
    }

    /**
     * @return void
     */
    public function test_gc_keeps_files_within_max_lifetime(): void
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
        $id = 'sessvalidate01';

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
        $id = 'sessvalidate01';
        $this->handler->write($id, 'payload');

        $this->assertTrue($this->handler->updateTimestamp($id, 'payload'));
        $this->assertSame('payload', $this->handler->read($id));

        $this->handler->destroy($id);
    }

    /**
     * An id with characters that can never name a session file is simply invalid
     * (strict mode then issues a fresh id) — validateId() must not throw.
     *
     * @return void
     */
    public function testValidateIdRejectsMalformedIdsWithoutThrowing(): void
    {
        $this->assertFalse($this->handler->validateId('../../etc/passwd'));
    }

    /**
     * A session idle longer than the configured lifetime is gone on read, without gc() having run
     * (gc is probabilistic and disabled on some distributions).
     *
     * @return void
     */
    public function testReadReturnsEmptyForASessionIdleLongerThanTheLifetime(): void
    {
        $handler = new FileSessionHandler($this->directory, 60);
        $handler->write('idle', 'data');
        touch($this->directory . '/sess_idle', time() - 61);

        $this->assertSame('', $handler->read('idle'));
        $this->assertFileDoesNotExist($this->directory . '/sess_idle');
    }

    /**
     * Under strict mode an expired id must not be adopted, so PHP issues a fresh one.
     *
     * @return void
     */
    public function testValidateIdRejectsASessionIdleLongerThanTheLifetime(): void
    {
        $handler = new FileSessionHandler($this->directory, 60);
        $handler->write('idle', 'data');
        touch($this->directory . '/sess_idle', time() - 61);

        $this->assertFalse($handler->validateId('idle'));
    }

    /**
     * @return void
     */
    public function testSessionWithinTheLifetimeIsStillReadable(): void
    {
        $handler = new FileSessionHandler($this->directory, 60);
        $handler->write('active', 'data');
        touch($this->directory . '/sess_active', time() - 30);

        $this->assertTrue($handler->validateId('active'));
        $this->assertSame('data', $handler->read('active'));
    }

    /**
     * lazy_write calls updateTimestamp() for an unchanged session; it must refresh the
     * activity time, or an active user would be logged out after the lifetime.
     *
     * @return void
     */
    public function testUpdateTimestampRefreshesTheIdleClock(): void
    {
        $handler = new FileSessionHandler($this->directory, 60);
        $handler->write('active', 'data');
        touch($this->directory . '/sess_active', time() - 50);

        $handler->updateTimestamp('active', 'data');
        clearstatcache();

        $this->assertGreaterThanOrEqual(time() - 1, (int) filemtime($this->directory . '/sess_active'));
    }

    /**
     * @return void
     */
    public function testLifetimeZeroDisablesTheIdleCheck(): void
    {
        $handler = new FileSessionHandler($this->directory, 0);
        $handler->write('ancient', 'data');
        touch($this->directory . '/sess_ancient', time() - 1_000_000);

        $this->assertTrue($handler->validateId('ancient'));
        $this->assertSame('data', $handler->read('ancient'));
    }
}
