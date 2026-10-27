<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\QueryCompiling;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\UnsupportedClause;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Orion;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FaultyEngine;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableResolver;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Adapter\PendingTask;
use Orion\Write\Tombstone;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * `Searcher::compile()` answers the question an agent cannot answer by reading the engine's documentation: what
 * exactly reaches the engine for a neutral query. It runs every step of `search()` short of the request: tenant,
 * schema binding, the offset window, the scope, the split into legs when core fuses.
 */
final class SearcherCompileTest extends TestCase
{
    private const string INDEX = 'products';

    private FaultyEngine $engine;
    private SettableResolver $resolver;
    private Graph $graph;

    protected function setUp(): void
    {
        $this->engine = new FaultyEngine(new InMemoryEngine());
        $this->resolver = new SettableResolver();
        $this->graph = $this->graph($this->engine);
        $this->graph->planner('memory')->apply(self::schema());
        $this->resolver->tenant = 'acme';
    }

    public function testCompileShowsTheBoundAndScopedQueryWithoutExecuting(): void
    {
        $this->engine->killBefore(FaultyEngine::SEARCH);

        $compiled = $this->graph->searcher()->compile(Query::on(self::INDEX)->match('title', 'running')->filter(Filter::term('brand', 'acme')));

        self::assertSame('memory', $compiled->engine);
        self::assertSame('products_v1', $compiled->index->value);
        self::assertSame(0, $this->engine->calls(FaultyEngine::SEARCH));
        $query = $compiled->query->toArray();
        self::assertSame(2.0, $query['query'][0]['boost'], 'The schema boost is bound as the clause weight.');
        self::assertSame([['type' => 'term', 'field' => 'brand', 'value' => 'acme'], ['type' => 'term', 'field' => 'org', 'value' => 'acme']], $query['filters'], 'The tenant filter is part of what the engine sees.');
        self::assertSame($query, $compiled->native, 'The in-memory engine runs the neutral query itself: that is its native form.');
        self::assertNull($compiled->legs);
        self::assertNull($compiled->fusion);
    }

    public function testAHybridQueryCompilesToItsTwoScopedLegsAndTheFusion(): void
    {
        $this->engine->killBefore(FaultyEngine::SEARCH);

        $compiled = $this->graph->searcher()->compile(Query::on(self::INDEX)->match('title', 'running')->vector('embedding', [1.0, 0.0], 5)->paginate(1, 3));

        self::assertNull($compiled->native);
        self::assertSame(['lexical', 'vector'], array_keys((array) $compiled->legs));
        $lexical = $compiled->legs['lexical'] ?? [];
        $vector = $compiled->legs['vector'] ?? [];
        self::assertNull($lexical['vector'], 'The lexical leg carries no vector.');
        self::assertSame([], $vector['query'], 'The vector leg carries no clause.');
        self::assertSame(['page' => 1, 'size' => 3], $vector['page'], 'The vector leg reads as deep as the page.');
        foreach ([$lexical, $vector] as $leg) {
            self::assertContains(['type' => 'term', 'field' => 'org', 'value' => 'acme'], $leg['filters'], 'Each leg is scoped.');
        }
        self::assertSame(Fusion::reciprocalRank()->toArray(), $compiled->fusion?->toArray());
        self::assertSame(['engine', 'index', 'query', 'native', 'legs', 'fusion'], array_keys($compiled->toArray()));
    }

    public function testAnEngineWithNativeFusionCompilesAHybridQueryWhole(): void
    {
        $engine = new FaultyEngine(new InMemoryEngine());
        $engine->claim(Capability::HybridFusion);
        $graph = $this->graph($engine);
        $graph->planner('memory')->apply(self::schema());
        $this->resolver->tenant = 'acme';

        $compiled = $graph->searcher()->compile(Query::on(self::INDEX)->match('title', 'running')->vector('embedding', [1.0, 0.0], 5));

        self::assertNotNull($compiled->native);
        self::assertNull($compiled->legs);
    }

    public function testCompileRefusesWhatSearchRefuses(): void
    {
        $searcher = $this->graph->searcher();
        $refused = [
            'an undeclared field' => [Query::on(self::INDEX)->match('colour', 'red'), InvalidArgumentException::class],
            'a page past the window' => [Query::on(self::INDEX)->paginate(101, 100), UnsupportedClause::class],
            'an index not built' => [Query::on('orders'), InvalidArgumentException::class],
            'a field sort on a hybrid query' => [Query::on(self::INDEX)->match('title', 'a')->vector('embedding', [1.0, 0.0], 2)->sort('brand'), InvalidArgumentException::class],
        ];
        foreach ($refused as $name => [$query, $exception]) {
            try {
                $searcher->compile($query);
                self::fail($name . ' was compiled.');
            } catch (InvalidArgumentException | UnsupportedClause $caught) {
                self::assertInstanceOf($exception, $caught, $name);
            }
        }
        $this->resolver->tenant = null;

        $this->expectException(NoTenant::class);

        $searcher->compile(Query::on(self::INDEX));
    }

    public function testAnEngineThatCannotShowItsQueryIsRefused(): void
    {
        $graph = $this->graph(self::opaque(new InMemoryEngine()));
        $graph->planner('memory')->apply(self::schema());
        $this->resolver->tenant = 'acme';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('memory');

        $graph->searcher()->compile(Query::on(self::INDEX));
    }

    private function graph(SearchAdapter&SchemaAdapter&WriteAdapter $engine): Graph
    {
        return Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(memory: $engine),
            tenancy: Tenancy::resolvedBy($this->resolver),
        ))->graph();
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for(self::INDEX)
            ->text('title', boost: 2.0)
            ->keyword('brand', filterable: true, sortable: true)
            ->vector('embedding', 2)
            ->orgKey('org');
    }

    /**
     * An engine that searches but is not a {@see QueryCompiling}: it cannot say what it would send.
     */
    private static function opaque(InMemoryEngine $inner): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new class ($inner) implements SearchAdapter, SchemaAdapter, WriteAdapter {
            public function __construct(private readonly InMemoryEngine $inner)
            {
            }

            public function supports(Capability $capability): bool
            {
                return $this->inner->supports($capability);
            }

            public function search(IndexName $index, Query $query): ResultSet
            {
                return $this->inner->search($index, $query);
            }

            public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
            {
                return $this->inner->raw($index, $native, ...$scope);
            }

            public function ensure(IndexSchema $schema): void
            {
                $this->inner->ensure($schema);
            }

            public function inspect(IndexName $index): ?LiveSchema
            {
                return $this->inner->inspect($index);
            }

            public function plan(IndexSchema $desired): MigrationPlan
            {
                return $this->inner->plan($desired);
            }

            public function apply(MigrationPlan $plan): PendingTask
            {
                return $this->inner->apply($plan);
            }

            public function drop(IndexName $index): void
            {
                $this->inner->drop($index);
            }

            public function alias(IndexName $alias, IndexName $physical): void
            {
                $this->inner->alias($alias, $physical);
            }

            public function resolve(IndexName $alias): ?IndexName
            {
                return $this->inner->resolve($alias);
            }

            public function index(IndexName $index, Document ...$docs): BulkResult
            {
                return $this->inner->index($index, ...$docs);
            }

            public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
            {
                return $this->inner->delete($index, ...$tombstones);
            }

            public function flush(IndexName $index, bool $await = false): void
            {
                $this->inner->flush($index, $await);
            }
        };
    }
}
