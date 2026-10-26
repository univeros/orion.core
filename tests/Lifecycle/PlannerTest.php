<?php

declare(strict_types=1);

namespace Orion\Tests\Lifecycle;

use DateTimeImmutable;
use LogicException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Lifecycle\Planner;
use Orion\Orion;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The failure modes the determinism harness does not stage; the behaviour itself is proven there, per engine.
 */
final class PlannerTest extends TestCase
{
    private InMemoryStorage $storage;
    private AliasFakeEngine $native;
    private Graph $graph;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->graph = Orion::create(new Config(
            storage: $this->storage,
            engines: new EngineRegistry(memory: new InMemoryEngine(), native: $this->native = new AliasFakeEngine()),
            tenancy: Tenancy::single(),
            clock: new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00')),
        ))->graph();
    }

    public function testThePlannerIsBuiltOncePerEngine(): void
    {
        self::assertInstanceOf(Planner::class, $this->graph->planner('memory'));
        self::assertSame($this->graph->planner('memory'), $this->graph->planner('memory'));
        self::assertSame($this->graph->registry(), $this->graph->registry());
        self::assertSame($this->graph->aliases(), $this->graph->aliases());
    }

    public function testAnAliasWithoutARegistrationFailsClosed(): void
    {
        $this->storage->insert(Schema::ALIAS, ['alias' => 'products', 'engine' => 'memory', 'physical' => 'products_v1', 'swapped_at' => new DateTimeImmutable()]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('products_v1');

        $this->graph->planner('memory')->plan(IndexSchema::for('products')->keyword('sku'));
    }

    public function testAFailedSwapDropsTheIndexItCreatedAndRegistersNothing(): void
    {
        $this->native->failNextAlias = true;

        try {
            $this->graph->planner('native')->apply(IndexSchema::for('products')->keyword('sku'));
            self::fail('The refused swap was swallowed.');
        } catch (RuntimeException) {
            self::assertNull($this->native->inspect(IndexName::of('products_v1')));
            self::assertSame([], $this->graph->registry()->versions(IndexName::of('products')));
            self::assertNull($this->graph->aliases()->current(IndexName::of('products')));
        }
    }

    public function testAnAliasOnAnotherEngineIsRefusedBeforeAnythingIsBuilt(): void
    {
        $this->storage->insert(Schema::ALIAS, ['alias' => 'products', 'engine' => 'native', 'physical' => 'products_v1', 'swapped_at' => new DateTimeImmutable()]);
        $this->graph->registry()->record(new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'native', 1, IndexSchema::for('products')->keyword('sku'), new DateTimeImmutable()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('native');

        $this->graph->planner('memory')->apply(IndexSchema::for('products')->keyword('sku'));
    }
}
