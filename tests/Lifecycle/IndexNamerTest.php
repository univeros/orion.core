<?php

declare(strict_types=1);

namespace Orion\Tests\Lifecycle;

use InvalidArgumentException;
use Orion\IndexName;
use Orion\Lifecycle\IndexNamer;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class IndexNamerTest extends TestCase
{
    public function testAPhysicalIndexIsTheAliasWithAVersionSuffix(): void
    {
        self::assertSame('products_v3', IndexNamer::physical(IndexName::of('products'), 3)->value);
    }

    public function testTheVersionIsPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexNamer::physical(IndexName::of('products'), 0);
    }

    public function testThePhysicalNameMustStillBeAValidIndexName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexNamer::physical(IndexName::of(str_repeat('a', 253)), 1);
    }
}
