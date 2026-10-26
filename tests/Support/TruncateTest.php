<?php

declare(strict_types=1);

namespace Orion\Tests\Support;

use Orion\Support\Assert;
use PHPUnit\Framework\TestCase;

final class TruncateTest extends TestCase
{
    public function testTruncatingNeverSplitsACharacter(): void
    {
        self::assertSame('ab', Assert::truncate('abñ', 3));
        self::assertSame('abñ', Assert::truncate('abñ', 4));
        self::assertSame('short', Assert::truncate('short', 100));
    }

    public function testInvalidUtf8IsReplacedNotStored(): void
    {
        self::assertSame("a\u{FFFD}b", Assert::truncate("a\xFFb", 100));
    }
}
