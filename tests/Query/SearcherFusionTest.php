<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\UnsupportedClause;
use Orion\Orion;
use Orion\Query\Contribution;
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Hit;
use Orion\Query\Page;
use Orion\Query\Query;
use Orion\Query\Searcher;
use Orion\Query\Sort;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableResolver;
use Orion\IndexName;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * A hybrid query on an engine without native fusion is fused in core by the searcher (v1 §5.1): each leg built from
 * the scoped query, reciprocal rank by default, the page sliced from the fused ranking.
 */
final class SearcherFusionTest extends TestCase
{
    private const string INDEX = 'products';

    private Graph $graph;
    private SettableResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SettableResolver();
        $this->graph = Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(memory: new InMemoryEngine()),
            tenancy: Tenancy::resolvedBy($this->resolver),
        ))->graph();
        $this->graph->planner('memory')->apply(IndexSchema::for(self::INDEX)
            ->text('title', boost: 2.0)
            ->keyword('brand', filterable: true, facetable: true, sortable: true)
            ->vector('embedding', 2)
            ->orgKey('org'));
        $this->resolver->tenant = 'acme';
        $corpus = [
            'a' => ['Trail running shoe', 'acme', [1.0, 0.0]],
            'b' => ['Running sock', 'acme', [0.0, 1.0]],
            'c' => ['Hiking boot', 'acme', [0.9, 0.1]],
            'd' => ['Running jacket', 'acme', [0.5, 0.5]],
        ];
        foreach ($corpus as $id => [$title, $brand, $embedding]) {
            $this->graph->outbox()->index(IndexName::of(self::INDEX), $id, ['title' => $title, 'brand' => $brand, 'embedding' => $embedding]);
        }
        $this->resolver->tenant = 'globex';
        $this->graph->outbox()->index(IndexName::of(self::INDEX), 'a', ['title' => 'Globex running shoe', 'brand' => 'globex', 'embedding' => [1.0, 0.0]]);
        $this->graph->drain()->run(IndexName::of(self::INDEX));
        $this->resolver->tenant = 'acme';
    }

    public function testTheEngineIsAskedForEachLegAndCoreFusesByReciprocalRankByDefault(): void
    {
        self::assertFalse($this->graph->engines()->get('memory')->supports(Capability::HybridFusion));

        $results = $this->graph->searcher()->search($this->hybrid());

        // lexical: a, b, d (running); vector near [1, 0]: a, c, d. a: 1/61 + 1/61; d: 1/63 + 1/63; b and c once.
        self::assertSame(['a', 'd', 'b', 'c'], self::ids($results->hits));
        self::assertSame(4, $results->total);
        self::assertTrue($results->totalIsEstimate);
        self::assertNotNull($results->fusion);
        self::assertSame(Fusion::DEFAULT_K, $results->fusion->fusion->k);
        self::assertSame(
            [['a', 'lexical', 1], ['b', 'lexical', 2], ['d', 'lexical', 3], ['a', 'vector', 1], ['c', 'vector', 2], ['d', 'vector', 3]],
            array_map(static fn(Contribution $c): array => [$c->id, $c->source, $c->rank], $results->fusion->contributions),
        );
    }

    public function testTheHitsCarryTheirSourceAndTheLexicalLegsHighlightsAndFacets(): void
    {
        $results = $this->graph->searcher()->search($this->hybrid()->highlight('title')->facet('brand'));

        self::assertSame('Trail running shoe', $results->hits[0]->source['title']);
        self::assertSame(['Trail <em>running</em> shoe'], $results->hits[0]->highlights['title']);
        self::assertSame([], $results->hits[3]->highlights);
        self::assertSame(['brand' => ['acme' => 3]], $results->facets);
    }

    public function testThePageIsSlicedFromTheFusedRanking(): void
    {
        $second = $this->graph->searcher()->search($this->hybrid()->paginate(2, 2));

        self::assertSame(['b', 'c'], self::ids($second->hits));
        self::assertSame(4, $second->total);
    }

    public function testTheTenantFilterSitsBeneathBothLegs(): void
    {
        $this->resolver->tenant = 'globex';

        $results = $this->graph->searcher()->search($this->hybrid());

        self::assertSame(['a'], self::ids($results->hits));
        self::assertSame('Globex running shoe', $results->hits[0]->source['title']);
        self::assertNotNull($results->fusion);
        self::assertSame(['a', 'a'], array_map(static fn(Contribution $c): string => $c->id, $results->fusion->contributions));
    }

    public function testAnExplicitFusionConstantIsHonoured(): void
    {
        $results = $this->graph->searcher()->search($this->hybrid()->fuse(Fusion::reciprocalRank(k: 1)));

        self::assertSame(1, $results->fusion?->fusion->k);
    }

    public function testWeightedFusionIsRefusedWithoutNativeFusion(): void
    {
        $this->expectException(UnsupportedClause::class);

        $this->graph->searcher()->search($this->hybrid()->fuse(Fusion::weighted(0.5, 0.5)));
    }

    public function testASortOtherThanRelevanceIsRefusedUnderFusion(): void
    {
        self::assertSame(['a', 'd', 'b', 'c'], self::ids($this->graph->searcher()->search($this->hybrid()->sort(Sort::SCORE, Direction::Desc))->hits));
        self::assertSame(['c', 'b', 'd', 'a'], self::ids($this->graph->searcher()->search($this->hybrid()->sort(Sort::SCORE, Direction::Asc))->hits));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ranks by fusion');

        $this->graph->searcher()->search($this->hybrid()->sort('brand'));
    }

    public function testFusionReadsAtMostAPageSizeOfEachLeg(): void
    {
        $this->expectException(UnsupportedClause::class);
        $this->expectExceptionMessage('core fusion');

        $this->graph->searcher()->search($this->hybrid()->paginate(2, Searcher::FUSION_WINDOW));
    }

    public function testAPagePastTheWindowIsRefusedOnEveryEngine(): void
    {
        try {
            $this->graph->searcher()->search(Query::on(self::INDEX)->paginate(Page::WINDOW / 10 + 1, 10));
            self::fail('paged past the window');
        } catch (UnsupportedClause $refused) {
            self::assertSame(Capability::CursorPagination, $refused->capability);
            self::assertSame('memory', $refused->engine);
            self::assertStringContainsString('result 10000 at most', $refused->getMessage());
        }
        self::assertSame([], $this->graph->searcher()->search(Query::on(self::INDEX)->paginate(Page::WINDOW / 10, 10))->hits);
    }

    public function testTheSchemaBoostReachesTheEngine(): void
    {
        $boosted = $this->graph->searcher()->search(Query::on(self::INDEX)->match('title', 'running'));

        // The in-memory engine scores one per matched token times the weight: the schema's boost of 2.
        self::assertSame(2.0, $boosted->hits[0]->score);
    }

    public function testAQueryThatDoesNotFitTheSchemaIsRefusedBeforeTheEngine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not declare');

        $this->graph->searcher()->search(Query::on(self::INDEX)->filter(Filter::term('colour', 'red')));
    }

    private function hybrid(): Query
    {
        return Query::on(self::INDEX)->match('title', 'running')->vector('embedding', [1.0, 0.0], 3);
    }

    /**
     * @param list<Hit> $hits
     * @return list<string>
     */
    private static function ids(array $hits): array
    {
        return array_map(static fn(Hit $hit): string => $hit->id, $hits);
    }
}
