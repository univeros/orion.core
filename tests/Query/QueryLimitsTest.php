<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use Orion\Query\Filter;
use Orion\Query\Query;
use PHPUnit\Framework\TestCase;

use function array_fill;

/**
 * The declarative form is bounded in every list, so one call cannot build an arbitrarily large request (the WP9
 * security review): clauses, filters, sorts, facets, highlights and a bool filter's children each have a ceiling.
 */
final class QueryLimitsTest extends TestCase
{
    public function testEveryListOfTheDeclarativeFormHasACeiling(): void
    {
        $clause = ['type' => 'match', 'field' => 'title', 'value' => 'a'];
        $filter = ['type' => 'term', 'field' => 'sku', 'value' => 'a'];
        $lists = [
            'query' => [$clause, Query::MAX_CLAUSES],
            'filters' => [$filter, Query::MAX_FILTERS],
            'sort' => [['field' => 'sku'], Query::MAX_SORTS],
            'facets' => [['field' => 'brand'], Query::MAX_FACETS],
            'highlight' => ['title', Query::MAX_HIGHLIGHTS],
        ];
        foreach ($lists as $key => [$item, $max]) {
            self::assertInstanceOf(Query::class, Query::fromArray(['index' => 'products', $key => array_fill(0, $max, $item)]), $key . ' at the ceiling');
            try {
                Query::fromArray(['index' => 'products', $key => array_fill(0, $max + 1, $item)]);
                self::fail($key . ' past the ceiling was accepted.');
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('query.' . $key, $refused->getMessage());
                self::assertStringContainsString((string) $max, $refused->getMessage());
            }
        }
    }

    public function testABoolFilterHasAtMostSoManyChildren(): void
    {
        $child = ['type' => 'term', 'field' => 'sku', 'value' => 'a'];
        self::assertInstanceOf(Filter::class, Filter::fromArray(['type' => 'bool', 'must' => array_fill(0, Filter::MAX_CHILDREN, $child)], 'f'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage((string) Filter::MAX_CHILDREN);

        Filter::fromArray(['type' => 'bool', 'should' => array_fill(0, Filter::MAX_CHILDREN + 1, $child)], 'f');
    }
}
