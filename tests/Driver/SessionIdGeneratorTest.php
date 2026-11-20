<?php

declare(strict_types=1);

namespace Tests\Driver;

use EzPhp\Session\Driver\SessionIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class SessionIdGeneratorTest
 *
 * @package Tests\Driver
 */
#[CoversClass(SessionIdGenerator::class)]
final class SessionIdGeneratorTest extends TestCase
{
    /**
     * @return void
     */
    public function test_generates_32_lowercase_hex_characters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SessionIdGenerator::generate());
    }

    /**
     * @return void
     */
    public function test_successive_ids_differ(): void
    {
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $ids[] = SessionIdGenerator::generate();
        }

        $this->assertCount(100, array_unique($ids));
    }
}
