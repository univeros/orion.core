<?php

declare(strict_types=1);

namespace Orion\Tests\Storage;

use Orion\Storage\Counters;
use Orion\Testing\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class CountersTest extends TestCase
{
    public function testASequenceStartsAtOneAndCountsUp(): void
    {
        $counters = new Counters(new InMemoryStorage());

        self::assertSame(0, $counters->value('receipt'));
        self::assertSame(1, $counters->next('receipt'));
        self::assertSame(2, $counters->next('receipt'));
        self::assertSame(2, $counters->value('receipt'));
    }

    public function testACursorOnlyMovesForward(): void
    {
        $counters = new Counters(new InMemoryStorage());

        $counters->advance('drain:products', 5);
        $counters->advance('drain:products', 3);
        $counters->advance('drain:products', 5);

        self::assertSame(5, $counters->value('drain:products'));
        $counters->advance('drain:products', 8);
        self::assertSame(8, $counters->value('drain:products'));
    }
}
