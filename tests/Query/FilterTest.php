<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use Orion\Query\BoolFilter;
use Orion\Query\Filter;
use Orion\Query\RangeFilter;
use Orion\Query\TermsFilter;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    public function testFactoriesBuildTheirTypes(): void
    {
        $range = Filter::range('price', gte: 10, lt: 100);
        self::assertInstanceOf(RangeFilter::class, $range);
        self::assertSame(10, $range->gte);
        self::assertSame(100, $range->lt);
        self::assertNull($range->gt);
        self::assertNull($range->lte);

        $terms = Filter::terms('brand', ['acme', 'globex']);
        self::assertInstanceOf(TermsFilter::class, $terms);
        self::assertSame(['acme', 'globex'], $terms->values);

        $bool = Filter::bool(must: [$range], mustNot: [$terms]);
        self::assertInstanceOf(BoolFilter::class, $bool);
        self::assertSame([$range], $bool->must);
        self::assertSame([], $bool->should);
        self::assertSame([$terms], $bool->mustNot);
    }

    public function testFiltersRoundTrip(): void
    {
        $filter = Filter::bool(
            must: [Filter::range('price', lte: 100), Filter::exists('sku')],
            should: [Filter::terms('brand', ['acme'])],
        );

        self::assertEquals($filter, Filter::fromArray($filter->toArray(), 'filter'));
    }

    public function testARangeNeedsABound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Filter::range('price');
    }

    public function testTermsNeedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Filter::terms('brand', []);
    }

    public function testTermsMustBeAList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        Filter::terms('brand', ['a' => 'acme']);
    }

    public function testABoolFilterNeedsAClause(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Filter::bool();
    }
}
