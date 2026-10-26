<?php

declare(strict_types=1);

namespace Orion\Tests\Tenancy;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Orion;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\Tenancy;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableResolver;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use Orion\Write\Document;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Every Orion operation on a shared index runs as the tenant the resolver names at that moment (v1 §9): searches
 * are filtered beneath the query, writes are qualified and stamped, and an index that does not fit the tenancy is
 * refused before it is built.
 */
final class ScopedOperationsTest extends TestCase
{
    private InMemoryStorage $storage;
    private InMemoryEngine $engine;
    private SettableResolver $resolver;
    private Graph $graph;
    private IndexName $products;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->engine = new InMemoryEngine();
        $this->resolver = new SettableResolver();
        $this->graph = $this->graph(Tenancy::resolvedBy($this->resolver));
        $this->products = IndexName::of('products');
    }

    public function testATenantFindsItsOwnDocumentsOnlyUnderItsOwnIds(): void
    {
        $this->build();
        $this->write('acme', 'p1', 'Trail running shoe');
        $this->write('globex', 'p1', 'Road running shoe');

        $this->resolver->tenant = 'acme';
        $hits = $this->graph->searcher()->search(Query::on('products')->match('title', 'running'))->hits;

        self::assertSame([['p1', 'Trail running shoe', 'acme']], self::summary($hits));
    }

    public function testTheTenantIsResolvedForEveryOperation(): void
    {
        $this->build();
        $this->write('acme', 'p1', 'Trail running shoe');
        $this->write('globex', 'p2', 'Road running shoe');
        $searcher = $this->graph->searcher();

        $this->resolver->tenant = 'acme';
        $acme = self::summary($searcher->search(Query::on('products'))->hits);
        $this->resolver->tenant = 'globex';
        $globex = self::summary($searcher->search(Query::on('products'))->hits);

        self::assertSame([['p1', 'Trail running shoe', 'acme']], $acme);
        self::assertSame([['p2', 'Road running shoe', 'globex']], $globex);
    }

    public function testATenantDeletesOnlyItsOwnDocument(): void
    {
        $this->build();
        $this->write('acme', 'p1', 'Trail running shoe');
        $this->write('globex', 'p1', 'Road running shoe');

        $this->resolver->tenant = 'acme';
        $this->graph->outbox()->delete($this->products, 'p1');
        $this->graph->drain()->run($this->products);

        self::assertSame([], $this->graph->searcher()->search(Query::on('products'))->hits);
        $this->resolver->tenant = 'globex';
        self::assertSame([['p1', 'Road running shoe', 'globex']], self::summary($this->graph->searcher()->search(Query::on('products'))->hits));
    }

    public function testTheEscapeHatchIsScopedToo(): void
    {
        $this->build();
        $this->write('acme', 'p1', 'Trail running shoe');
        $this->write('globex', 'p2', 'Road running shoe');

        $this->resolver->tenant = 'acme';
        $hits = $this->graph->searcher()->raw($this->products, static fn(array $source): bool => true)->hits;

        self::assertSame([['p1', 'Trail running shoe', 'acme']], self::summary($hits));
    }

    public function testAHitOutsideTheTenantFromTheEngineFailsTheSearch(): void
    {
        $this->build();
        // A document stamped for acme under another tenant's id: only the engine could hold it, never Orion's writer.
        $this->engine->index($this->physical(), new Document('globex:p9', ['title' => 'Forged', 'org_id' => 'acme'], 1));
        $this->resolver->tenant = 'acme';

        $this->expectException(LogicException::class);

        $this->graph->searcher()->search(Query::on('products'));
    }

    public function testWithoutATenantNothingIsSearchedOrRecorded(): void
    {
        $this->build();
        $this->resolver->tenant = null;

        try {
            $this->graph->searcher()->search(Query::on('products'));
            self::fail('A search ran without a tenant.');
        } catch (NoTenant) {
        }
        try {
            $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Trail running shoe']);
            self::fail('A write was recorded without a tenant.');
        } catch (NoTenant) {
        }

        self::assertSame(0, $this->storage->count(Schema::OUTBOX, []));
    }

    public function testWritingToAnIndexNotYetBuiltIsRefusedUnderAResolver(): void
    {
        $this->resolver->tenant = 'acme';

        $this->expectException(InvalidArgumentException::class);

        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Trail running shoe']);
    }

    public function testAResolverNeedsAnOrgKeyOnEveryIndex(): void
    {
        $unkeyed = IndexSchema::for('products')->text('title');

        foreach ([fn() => $this->graph->planner('memory')->apply($unkeyed), fn() => $this->graph->reindexer('memory')->reindex($unkeyed)] as $build) {
            try {
                $build();
                self::fail('An index without an org key was built under a tenant resolver.');
            } catch (LogicException $refused) {
                self::assertStringContainsString('org key', $refused->getMessage());
            }
        }
        self::assertSame([], $this->graph->registry()->versions($this->products));
    }

    public function testAnIndexHoldingDocumentsNeverChangesItsOrgKey(): void
    {
        // Entries recorded under the old key (or none) carry no tenant the new key could filter on: a replay would
        // build an index whose documents no tenant can find.
        $single = $this->graph(Tenancy::single());
        $single->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $single->outbox()->index($this->products, 'p1', ['title' => 'Trail running shoe']);

        try {
            $this->graph->reindexer('memory')->reindex($this->schema());
            self::fail('Tenancy was added to an index holding untenanted documents.');
        } catch (LogicException $refused) {
            self::assertStringContainsString('org key', $refused->getMessage());
        }
        self::assertCount(1, $this->graph->registry()->versions($this->products));
    }

    public function testARenamedOrgKeyIsRefusedOnceDocumentsExist(): void
    {
        $this->build();
        $this->write('acme', 'p1', 'Trail running shoe');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('org key');

        $this->graph->reindexer('memory')->reindex(IndexSchema::for('products')->text('title')->orgKey('tenant'));
    }

    public function testAnEmptyIndexMayChangeItsOrgKey(): void
    {
        $this->build();

        $result = $this->graph->reindexer('memory')->reindex(IndexSchema::for('products')->text('title')->orgKey('tenant'));

        self::assertSame('tenant', $result->to->schema->orgKey);
    }

    public function testSingleTenancyRefusesAnOrgKey(): void
    {
        $graph = $this->graph(Tenancy::single());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('org key');

        $graph->planner('memory')->apply($this->schema());
    }

    public function testSingleTenancySearchesAndWritesAsBefore(): void
    {
        $graph = $this->graph(Tenancy::single());
        $graph->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $graph->outbox()->index($this->products, 'p1', ['title' => 'Trail running shoe']);
        $graph->drain()->run($this->products);

        $hits = $graph->searcher()->search(Query::on('products'))->hits;

        self::assertSame(['p1'], array_map(static fn(Hit $hit): string => $hit->id, $hits));
        self::assertSame(['title' => 'Trail running shoe'], $hits[0]->source);
    }

    private function graph(Tenancy $tenancy): Graph
    {
        return Orion::create(new Config(storage: $this->storage, engines: new EngineRegistry(memory: $this->engine), tenancy: $tenancy))->graph();
    }

    private function schema(): IndexSchema
    {
        return IndexSchema::for('products')->text('title')->orgKey('org_id');
    }

    private function build(): void
    {
        $this->graph->planner('memory')->apply($this->schema());
    }

    private function physical(): IndexName
    {
        return ($this->graph->aliases()->current($this->products) ?? throw new LogicException('Not built.'))->physical;
    }

    private function write(string $tenant, string $id, string $title): void
    {
        $this->resolver->tenant = $tenant;
        $this->graph->outbox()->index($this->products, $id, ['title' => $title]);
        $this->graph->drain()->run($this->products);
    }

    /**
     * @param list<Hit> $hits
     * @return list<array{string, mixed, mixed}>
     */
    private static function summary(array $hits): array
    {
        return array_map(static fn(Hit $hit): array => [$hit->id, $hit->source['title'] ?? null, $hit->source['org_id'] ?? null], $hits);
    }
}
