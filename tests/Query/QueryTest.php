<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\Direction;
use Orion\Query\Facet;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\FusionStrategy;
use Orion\Query\Page;
use Orion\Query\Query;
use Orion\Query\Sort;
use Orion\Query\TermsFilter;
use Orion\Query\VectorClause;
use Orion\Schema\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function range;
use function str_repeat;
use function strlen;

use const INF;
use const NAN;
use const PHP_INT_MAX;

final class QueryTest extends TestCase
{
    public function testTheBuilderProducesTheAst(): void
    {
        $query = self::hybrid();

        self::assertSame('products', $query->index->value);
        self::assertEquals([Clause::match('title', 'running shoe')], $query->clauses);
        self::assertNotNull($query->vector);
        self::assertSame(50, $query->vector->k);
        self::assertNotNull($query->fusion);
        self::assertSame(FusionStrategy::ReciprocalRank, $query->fusion->strategy);
        self::assertCount(2, $query->filters);
        self::assertSame(['brand'], [$query->facets[0]->field]);
        self::assertEquals([new Sort(Sort::SCORE, Direction::Desc)], $query->sorts);
        self::assertSame(['title'], $query->highlights);
        self::assertSame(1, $query->page->page);
        self::assertSame(20, $query->page->size);
    }

    public function testEveryLexicalClauseKindIsBuilt(): void
    {
        $query = Query::on('products')
            ->match('title', 'shoe')
            ->term('sku', 'SKU-1')
            ->prefix('title', 'sho')
            ->phrase('description', 'trail running');

        self::assertSame(
            [ClauseKind::Match, ClauseKind::Term, ClauseKind::Prefix, ClauseKind::Phrase],
            [$query->clauses[0]->kind, $query->clauses[1]->kind, $query->clauses[2]->kind, $query->clauses[3]->kind],
        );
    }

    public function testBuildingReturnsACopy(): void
    {
        $base = Query::on('products');
        $matched = $base->match('title', 'shoe');

        self::assertSame([], $base->clauses);
        self::assertCount(1, $matched->clauses);
    }

    public function testTheDefaultsAreTheFirstPageAndNoFusion(): void
    {
        $query = Query::on('products');

        self::assertSame(1, $query->page->page);
        self::assertSame(Query::DEFAULT_PAGE_SIZE, $query->page->size);
        self::assertNull($query->fusion);
        self::assertNull($query->vector);
    }

    #[DataProvider('queries')]
    public function testTheAstRoundTripsThroughItsDeclarativeForm(Query $query): void
    {
        $data = $query->toArray();

        self::assertEquals($query, Query::fromArray($data));
        self::assertSame($data, Query::fromArray($data)->toArray());
    }

    /**
     * @return iterable<string, array{Query}>
     */
    public static function queries(): iterable
    {
        yield 'empty' => [Query::on('products')];
        yield 'hybrid' => [self::hybrid()];
        yield 'weighted' => [Query::on('products')->match('title', 'x')->vector('embedding', [1.0], k: 5)->fuse(Fusion::weighted(lexical: 0.3, vector: 0.7))];
        yield 'a match over several weighted fields' => [Query::on('products')->match(['title' => 3.0, 'description' => 1.0], 'shoe')->term('sku', 'A1')];
        yield 'a boosted single clause' => [Query::fromArray(['index' => 'products', 'query' => [['type' => 'prefix', 'field' => 'title', 'value' => 'sho', 'boost' => 2.5]]])];
        yield 'every clause and filter' => [
            Query::on('products')
                ->term('in_stock', true)
                ->prefix('title', 'sho')
                ->phrase('description', 'trail running')
                ->filter(Filter::terms('brand', ['acme', 'globex']))
                ->filter(Filter::exists('sku'))
                ->filter(Filter::range('rating', gt: 2, lt: 4.5))
                ->filter(Filter::range('released', gte: '2026-01-01'))
                ->filter(Filter::bool(
                    must: [Filter::term('in_stock', true)],
                    should: [Filter::term('brand', 'acme'), Filter::term('brand', 'globex')],
                    mustNot: [Filter::bool(must: [Filter::exists('discontinued')])],
                ))
                ->sort('price', Direction::Asc)
                ->sort(Sort::SCORE, Direction::Desc)
                ->facet('brand', size: 5)
                ->highlight('title', 'description')
                ->paginate(page: 3, size: 50),
        ];
    }

    public function testTheDeclarativeFormIsPlainData(): void
    {
        self::assertSame([
            'index' => 'products',
            'query' => [['type' => 'match', 'field' => 'title', 'value' => 'running shoe']],
            'vector' => ['field' => 'embedding', 'vector' => [0.1, 0.2, 0.3], 'k' => 50],
            'fusion' => ['strategy' => 'reciprocal_rank', 'k' => 60],
            'filters' => [
                ['type' => 'range', 'field' => 'price', 'lte' => 100],
                ['type' => 'term', 'field' => 'in_stock', 'value' => true],
            ],
            'sort' => [['field' => '_score', 'direction' => 'desc']],
            'facets' => [['field' => 'brand', 'size' => 10]],
            'highlight' => ['title'],
            'page' => ['page' => 1, 'size' => 20],
        ], self::hybrid()->toArray());
    }

    public function testAMatchReadsSeveralFieldsWithWeights(): void
    {
        $listed = Query::on('products')->match(['title', 'description'], 'shoe')->clauses[0];
        $weighted = Query::on('products')->match(['title' => 3.0, 'description' => 1.0], 'shoe')->clauses[0];

        self::assertSame(['title' => 1.0, 'description' => 1.0], $listed->fields);
        self::assertSame('title', $listed->field);
        self::assertSame(['title' => 3.0, 'description' => 1.0], $weighted->fields);
        self::assertSame(['type' => 'match', 'fields' => ['title' => 3.0, 'description' => 1.0], 'value' => 'shoe'], $weighted->toArray());
        self::assertEquals($weighted, Clause::fromArray(['type' => 'match', 'fields' => ['title' => 3.0, 'description' => 1.0], 'value' => 'shoe'], 'q'));
        self::assertEquals($listed, Clause::fromArray(['type' => 'match', 'fields' => ['title', 'description'], 'value' => 'shoe'], 'q'));
    }

    public function testAClauseIsWeightedByFactorsPerField(): void
    {
        $clause = Clause::match(['title' => 2.0, 'description' => 1.0], 'shoe')->weighted(['title' => 3.0]);

        self::assertSame(['title' => 6.0, 'description' => 1.0], $clause->fields);
        self::assertSame(['type' => 'term', 'field' => 'sku', 'value' => 'A1', 'boost' => 2.0], Clause::term('sku', 'A1')->weighted(['sku' => 2.0])->toArray());
        self::assertSame(['type' => 'term', 'field' => 'sku', 'value' => 'A1'], Clause::term('sku', 'A1')->toArray());
    }

    public function testOnlyAMatchReadsSeveralFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only a match reads several fields');

        Clause::fromArray(['type' => 'phrase', 'fields' => ['title', 'description'], 'value' => 'trail shoe'], 'q');
    }

    public function testTheLegsOfAHybridQueryAreBuiltFromIt(): void
    {
        $query = self::hybrid()->paginate(2, 10);

        $lexical = $query->lexicalLeg(20);
        $vector = $query->vectorLeg(20);

        self::assertNull($lexical->vector);
        self::assertNull($lexical->fusion);
        self::assertEquals($query->clauses, $lexical->clauses);
        self::assertEquals($query->filters, $lexical->filters);
        self::assertSame(['title'], $lexical->highlights);
        self::assertSame([], $lexical->sorts);
        self::assertSame([], $vector->sorts);
        self::assertSame(['page' => 1, 'size' => 20], $lexical->page->toArray());
        self::assertSame([], $vector->clauses);
        self::assertNotNull($vector->vector);
        self::assertSame([], $vector->facets);
        self::assertSame([], $vector->highlights);
        self::assertEquals($query->filters, $vector->filters);
        self::assertSame(20, $query->page->depth());
    }

    public function testOmittedKeysTakeTheirDefaults(): void
    {
        self::assertEquals(Query::on('products'), Query::fromArray(['index' => 'products']));
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidDeclarativeInputIsRejectedWithItsPath(array $data, string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($path);

        Query::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'missing index' => [[], 'query.index'];
        yield 'unknown key' => [['index' => 'products', 'filter' => []], 'query.filter'];
        yield 'unknown clause' => [['index' => 'products', 'query' => [['type' => 'fuzzy', 'field' => 'title', 'value' => 'x']]], 'query.query[0].type'];
        yield 'non-string match' => [['index' => 'products', 'query' => [['type' => 'match', 'field' => 'title', 'value' => 3]]], 'query.query[0].value'];
        yield 'unknown filter' => [['index' => 'products', 'filters' => [['type' => 'geo', 'field' => 'at']]], 'query.filters[0].type'];
        yield 'empty range' => [['index' => 'products', 'filters' => [['type' => 'range', 'field' => 'price']]], 'query.filters[0]'];
        yield 'bad direction' => [['index' => 'products', 'sort' => [['field' => 'price', 'direction' => 'up']]], 'query.sort[0].direction'];
        yield 'zero page' => [['index' => 'products', 'page' => ['page' => 0, 'size' => 20]], 'query.page.page'];
        yield 'non-numeric vector' => [['index' => 'products', 'vector' => ['field' => 'embedding', 'vector' => ['a'], 'k' => 5]], 'query.vector.vector[0]'];
        yield 'bad fusion' => [['index' => 'products', 'fusion' => ['strategy' => 'borda']], 'query.fusion.strategy'];
        yield 'field and fields' => [['index' => 'products', 'query' => [['type' => 'match', 'field' => 'title', 'fields' => ['title'], 'value' => 'x']]], 'query.query[0] needs field or fields'];
        yield 'boost with fields' => [['index' => 'products', 'query' => [['type' => 'match', 'fields' => ['title'], 'boost' => 2, 'value' => 'x']]], 'query.query[0].boost'];
        yield 'empty fields' => [['index' => 'products', 'query' => [['type' => 'match', 'fields' => [], 'value' => 'x']]], 'query.query[0].fields'];
        yield 'non-numeric weight' => [['index' => 'products', 'query' => [['type' => 'match', 'fields' => ['title' => 'high'], 'value' => 'x']]], 'query.query[0].fields.title'];
        yield 'bad index' => [['index' => 'Products!'], 'index name'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('hostileQueries')]
    public function testHostileOrOversizedInputIsRejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::fromArray(['index' => 'products', ...$data]);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function hostileQueries(): iterable
    {
        $match = static fn(string $field, string $value = 'x'): array => ['query' => [['type' => 'match', 'field' => $field, 'value' => $value]]];

        yield 'wildcard field' => [$match('*')];
        yield 'wildcard in fields' => [['query' => [['type' => 'match', 'fields' => ['*'], 'value' => 'x']]]];
        yield 'zero weight' => [['query' => [['type' => 'match', 'fields' => ['title' => 0], 'value' => 'x']]]];
        yield 'negative boost' => [['query' => [['type' => 'term', 'field' => 'sku', 'value' => 'x', 'boost' => -1]]]];
        yield 'non-finite boost' => [['query' => [['type' => 'term', 'field' => 'sku', 'value' => 'x', 'boost' => INF]]]];
        yield 'metadata field' => [$match('_source')];
        yield 'newline field' => [$match("title\n")];
        yield 'local params facet' => [['facets' => [['field' => '{!terms=a}brand']]]];
        yield 'function sort' => [['sort' => [['field' => 'div(price,0)']]]];
        yield 'wildcard highlight' => [['highlight' => ['*']]];
        yield 'oversized match text' => [$match('title', str_repeat('a', Clause::MAX_VALUE_LENGTH + 1))];
        yield 'oversized page' => [['page' => ['page' => 1, 'size' => Page::MAX_SIZE + 1]]];
        yield 'overflowing offset' => [['page' => ['page' => PHP_INT_MAX, 'size' => 2]]];
        yield 'oversized k' => [['vector' => ['field' => 'embedding', 'vector' => [0.1], 'k' => VectorClause::MAX_K + 1]]];
        yield 'oversized vector' => [['vector' => ['field' => 'embedding', 'vector' => array_fill(0, Field::MAX_DIMS + 1, 0.1), 'k' => 5]]];
        yield 'non-finite vector' => [['vector' => ['field' => 'embedding', 'vector' => [NAN], 'k' => 5]]];
        yield 'non-finite weight' => [['fusion' => ['strategy' => 'weighted', 'lexical' => INF, 'vector' => 1.0]]];
        yield 'non-finite bound' => [['filters' => [['type' => 'range', 'field' => 'price', 'lte' => INF]]]];
        yield 'too many terms' => [['filters' => [['type' => 'terms', 'field' => 'brand', 'values' => range(0, TermsFilter::MAX_VALUES)]]]];
        yield 'oversized facet' => [['facets' => [['field' => 'brand', 'size' => Facet::MAX_SIZE + 1]]]];
        yield 'too deep' => [['filters' => [self::nestedBool(Filter::MAX_DEPTH + 1)]]];
    }

    public function testNestingUpToTheLimitIsAccepted(): void
    {
        $query = Query::fromArray(['index' => 'products', 'filters' => [self::nestedBool(Filter::MAX_DEPTH)]]);

        self::assertCount(1, $query->filters);
    }

    public function testDottedPathsAddressObjectFields(): void
    {
        self::assertSame('variants.size', Query::on('products')->term('variants.size', 'M')->clauses[0]->field);
    }

    public function testErrorMessagesTruncateEchoedInput(): void
    {
        try {
            Query::fromArray(['index' => 'products', 'filters' => [['type' => str_repeat('x', 500), 'field' => 'a']]]);
            self::fail('accepted');
        } catch (InvalidArgumentException $exception) {
            self::assertLessThan(200, strlen($exception->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function nestedBool(int $depth): array
    {
        $filter = ['type' => 'exists', 'field' => 'sku'];
        for ($i = 0; $i < $depth; ++$i) {
            $filter = ['type' => 'bool', 'must' => [$filter]];
        }

        return $filter;
    }

    public function testAnEmptyFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::on('products')->match('', 'shoe');
    }

    public function testAVectorNeedsDimensionsAndAPositiveK(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::on('products')->vector('embedding', [0.1], k: 0);
    }

    public function testAnEmptyVectorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::on('products')->vector('embedding', [], k: 5);
    }

    public function testAPageSizeMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::on('products')->paginate(page: 1, size: 0);
    }

    private static function hybrid(): Query
    {
        return Query::on('products')
            ->match('title', 'running shoe')
            ->vector('embedding', [0.1, 0.2, 0.3], k: 50)
            ->fuse(Fusion::reciprocalRank())
            ->filter(Filter::range('price', lte: 100))
            ->filter(Filter::term('in_stock', true))
            ->facet('brand')
            ->sort(Sort::SCORE, Direction::Desc)
            ->highlight('title')
            ->paginate(page: 1, size: 20);
    }
}
