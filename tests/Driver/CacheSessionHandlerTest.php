<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Session\Driver\CacheSessionHandler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * CacheSessionHandler on ez-php/cache's ArrayDriver.
 *
 * @package Tests\Driver
 */
#[CoversClass(CacheSessionHandler::class)]
final class CacheSessionHandlerTest extends TestCase
{
    public function test_write_read_destroy_round_trip(): void
    {
        $handler = new CacheSessionHandler(new ArrayDriver());

        $this->assertTrue($handler->open('', 'PHPSESSID'));
        $this->assertSame('', $handler->read('abc'));
        $this->assertTrue($handler->write('abc', 'foo|s:3:"bar";'));
        $this->assertSame('foo|s:3:"bar";', $handler->read('abc'));
        $this->assertTrue($handler->destroy('abc'));
        $this->assertSame('', $handler->read('abc'));
        $this->assertTrue($handler->close());
    }

    public function test_keys_are_prefixed_and_carry_the_ttl(): void
    {
        $cache = new ArrayDriver();
        $handler = new CacheSessionHandler($cache, ttl: 60, prefix: 'sess:');

        $handler->write('abc', 'data');

        $this->assertSame('data', $cache->get('sess:abc'));
        $this->assertFalse($cache->has('abc'));
    }

    public function test_expired_session_reads_empty_and_is_no_longer_valid(): void
    {
        $cache = new ArrayDriver();
        $handler = new CacheSessionHandler($cache);
        $handler->write('abc', 'data');

        $cache->set('session:abc', 'data', -1); // the TTL ran out

        $this->assertSame('', $handler->read('abc'));
        $this->assertFalse($handler->validateId('abc'));
    }

    public function test_validate_id_and_update_timestamp(): void
    {
        $handler = new CacheSessionHandler(new ArrayDriver());

        $this->assertFalse($handler->validateId('abc'), 'unknown id is rejected (strict mode)');
        $this->assertTrue($handler->updateTimestamp('abc', 'data'));
        $this->assertTrue($handler->validateId('abc'));
        $this->assertSame(0, $handler->gc(1440));
    }

    public function test_non_string_cache_value_reads_as_empty(): void
    {
        $cache = new ArrayDriver();
        $cache->set('session:abc', ['not' => 'a session string']);

        $this->assertSame('', (new CacheSessionHandler($cache))->read('abc'));
    }

    /**
     * A TTL of 0 would mean "never expire" to the cache, and gc() reclaims nothing,
     * so the handler refuses it instead of growing the cache without bound.
     */
    public function test_a_ttl_below_one_second_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheSessionHandler(new ArrayDriver(), ttl: 0);
    }
}
