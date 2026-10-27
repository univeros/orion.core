<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use Orion\Query\Binder;
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\Sort;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A query is bound to the registered schema before any engine sees it: fields exist and are flagged for the use,
 * values are of the field's type, and the schema's query-time boost reaches each clause as a weight.
 */
final class BinderTest extends TestCase
{
    public function testAWellTypedQueryBindsAndTakesTheSchemaBoosts(): void
    {
        $query = Query::on('products')
            ->match(['title', 'description'], 'shoe')
            ->term('sku', 'A1')
            ->prefix('title', 'sho')
            ->phrase('description', 'trail shoe')
            ->vector('embedding', [1.0, 0.0, 0.0], 3)
            ->filter(Filter::term('in_stock', true))
            ->filter(Filter::terms('brand', ['acme', 'globex']))
            ->filter(Filter::range('price', gte: 10, lt: 99.5))
            ->filter(Filter::range('released', gte: '2026-01-01', lt: '2026-12-31T23:59:59.250Z'))
            ->filter(Filter::bool(must: [Filter::exists('variants.size')], mustNot: [Filter::term('stock', 2.0)]))
            ->sort('price', Direction::Desc)
            ->sort(Sort::SCORE)
            ->facet('brand')
            ->highlight('title');

        $bound = Binder::bind($query, self::schema());

        self::assertSame(['title' => 3.0, 'description' => 1.0], $bound->clauses[0]->fields);
        self::assertSame(['sku' => 1.0], $bound->clauses[1]->fields);
        self::assertSame(['title' => 3.0], $bound->clauses[2]->fields);
        self::assertEquals($query->filters, $bound->filters);
        self::assertEquals($query->vector, $bound->vector);
    }

    public function testAQueryWithoutClausesIsReturnedAsIs(): void
    {
        $query = Query::on('products')->filter(Filter::term('sku', 'A1'));

        self::assertSame($query, Binder::bind($query, self::schema()));
    }

    public function testACallersWeightMultipliesTheSchemaBoost(): void
    {
        $bound = Binder::bind(Query::on('products')->match(['title' => 2.0], 'shoe'), self::schema());

        self::assertSame(['title' => 6.0], $bound->clauses[0]->fields);
    }

    #[DataProvider('misfits')]
    public function testAQueryThatDoesNotFitTheSchemaIsRefusedNamingWhy(Query $query, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Binder::bind($query, self::schema());
    }

    /**
     * @return iterable<string, array{Query, string}>
     */
    public static function misfits(): iterable
    {
        $q = Query::on('products');

        yield 'undeclared clause field' => [$q->match('name', 'x'), '"name", which "products" does not declare'];
        yield 'undeclared field in a multi-field match' => [$q->match(['title', 'name'], 'x'), '"name", which "products" does not declare'];
        yield 'match on a number' => [$q->match('price', 'x'), '"price" of "products" has type float, which a match cannot read'];
        yield 'prefix on a date' => [$q->prefix('released', '2026'), 'has type date, which a prefix cannot read'];
        yield 'phrase on a vector' => [$q->phrase('embedding', 'x y'), 'has type vector, which a phrase cannot read'];
        yield 'term on an object' => [$q->term('variants', 'x'), 'has type object, which a term cannot read'];
        yield 'term of the wrong type' => [$q->term('stock', 'many'), '"stock" of "products" has type integer; "many" is not a whole number'];
        yield 'term on an unflagged keyword value type' => [$q->term('sku', true), '"true" is not a string'];
        yield 'undeclared filter field' => [$q->filter(Filter::term('colour', 'red')), '"colour", which "products" does not declare'];
        yield 'filter on an unflagged field' => [$q->filter(Filter::term('note', 'x')), '"note" of "products" is not filterable'];
        yield 'filter on text' => [$q->filter(Filter::term('title', 'x')), '"title" of "products" is not filterable'];
        yield 'range on a boolean' => [$q->filter(Filter::range('in_stock', gte: 1)), 'is not filterable by a range filter'];
        yield 'terms on a boolean with a string' => [$q->filter(Filter::terms('in_stock', [true, 'yes'])), '"yes" is not a boolean'];
        yield 'fractional integer' => [$q->filter(Filter::term('stock', 2.5)), '"2.5" is not a whole number'];
        yield 'bad date' => [$q->filter(Filter::range('released', gte: 'yesterday')), '"yesterday" is not a date in ISO 8601'];
        yield 'impossible date' => [$q->filter(Filter::range('released', gte: '2026-13-01')), '"2026-13-01" is not a date in ISO 8601'];
        yield 'impossible time' => [$q->filter(Filter::term('released', '2026-01-01T24:00')), '"2026-01-01T24:00" is not a date in ISO 8601'];
        yield 'integer beyond 64 bits' => [$q->filter(Filter::term('stock', 1e30)), 'is not a whole number'];
        yield 'term on text' => [$q->term('title', 'shoe'), '"title" of "products" has type text, which a term cannot read'];
        yield 'float with a string' => [$q->filter(Filter::range('price', lte: 'cheap')), '"cheap" is not a number'];
        yield 'keyword with a boolean' => [$q->filter(Filter::term('sku', true)), '"true" is not a string'];
        yield 'nested in a bool' => [$q->filter(Filter::bool(should: [Filter::exists('sku'), Filter::term('nowhere', 1)])), '"nowhere", which "products" does not declare'];
        yield 'exists on nothing' => [$q->filter(Filter::exists('nowhere')), '"nowhere", which "products" does not declare'];
        yield 'sort on an unsortable field' => [$q->sort('brand'), '"brand" of "products" is not sortable'];
        yield 'sort on text' => [$q->sort('title'), '"title" of "products" is not sortable'];
        yield 'facet on an unfacetable field' => [$q->facet('sku'), '"sku" of "products" is not facetable'];
        yield 'highlight a keyword' => [$q->highlight('sku'), '"sku" of "products" is not a text field'];
        yield 'vector on a text field' => [$q->vector('title', [1.0], 1), '"title" of "products" is not a vector field'];
        yield 'vector of the wrong dimensions' => [$q->vector('embedding', [1.0, 0.0], 1), '"embedding" of "products" has 3 dimensions, the query vector 2'];
        yield 'undeclared vector field' => [$q->vector('vec', [1.0], 1), '"vec", which "products" does not declare'];
    }

    public function testObjectFieldsAreAddressedByDottedPath(): void
    {
        $schema = self::schema();

        self::assertSame('size', $schema->fieldAt('variants.size')?->name);
        self::assertNull($schema->fieldAt('variants.colour'));
        self::assertNull($schema->fieldAt('title.raw'));
        self::assertCount(1, Binder::bind(Query::on('products')->filter(Filter::term('variants.size', 'M')), $schema)->filters);
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')
            ->text('title', language: 'en', boost: 3.0)
            ->text('description', language: 'en')
            ->text('note')
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', filterable: true, facetable: true)
            ->float('price', filterable: true, sortable: true)
            ->integer('stock', filterable: true)
            ->boolean('in_stock', filterable: true)
            ->date('released', filterable: true, sortable: true)
            ->vector('embedding', 3)
            ->with(Field::object('variants', Field::keyword('size', filterable: true)));
    }
}
