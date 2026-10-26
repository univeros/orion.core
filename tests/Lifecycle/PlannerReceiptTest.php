<?php

declare(strict_types=1);

namespace Orion\Tests\Lifecycle;

use DateTimeImmutable;
use LogicException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Orion;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class PlannerReceiptTest extends TestCase
{
    private InMemoryStorage $storage;
    private InMemoryEngine $engine;
    private Graph $graph;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->graph = $this->graph(SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1'));
    }

    public function testCreatingAnIndexIssuesAReceipt(): void
    {
        $this->graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp']);

        $outcome = $this->graph->planner('memory')->apply(self::schema());

        $receipt = $outcome->receipt;
        self::assertNotNull($receipt);
        self::assertSame([1, Operation::Created, 'products', 'memory', null, 1, 0, 1, 1], [$receipt->sequence, $receipt->operation, $receipt->index->value, $receipt->engine, $receipt->fromVersion, $receipt->toVersion, $receipt->documentCount, $receipt->fromOffset, $receipt->toOffset]);
        self::assertSame(self::schema()->hash(), $receipt->schemaHash);
        self::assertSame($receipt->toArray(), $outcome->toArray()['receipt']);
    }

    public function testApplyingInPlaceIssuesAReceiptAndAnUnchangedApplyDoesNot(): void
    {
        $this->graph->planner('memory')->apply(self::schema());

        $applied = $this->graph->planner('memory')->apply(self::schema()->keyword('sku'));
        $unchanged = $this->graph->planner('memory')->apply(self::schema()->keyword('sku'));

        self::assertSame(Operation::Applied, $applied->operation);
        self::assertSame([2, 1, 1], [$applied->receipt?->sequence, $applied->receipt?->fromVersion, $applied->receipt?->toVersion]);
        self::assertSame(Operation::Unchanged, $unchanged->operation);
        self::assertNull($unchanged->receipt);
        self::assertSame(2, $this->storage->count(Schema::RECEIPT, []));
        self::assertTrue($this->graph->verifier()->verify()->ok());
    }

    public function testABreakingPlanIssuesNoReceipt(): void
    {
        $this->graph->planner('memory')->apply(self::schema());

        $outcome = $this->graph->planner('memory')->apply(IndexSchema::for('products')->keyword('title'));

        self::assertSame(Operation::ReindexRequired, $outcome->operation);
        self::assertNull($outcome->receipt);
        self::assertSame(1, $this->storage->count(Schema::RECEIPT, []));
    }

    public function testAChangeMadeWithReceiptsOffIsCaughtByVerify(): void
    {
        $this->graph->planner('memory')->apply(self::schema());
        // Someone applies a change through a bootstrap without the key: the registry moves, no receipt is issued.
        $this->graph(null)->planner('memory')->apply(IndexSchema::for('products')->text('title', boost: 2.0));

        $breaks = $this->graph(SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1'))->verifier()->verify()->breaks;

        self::assertCount(1, $breaks);
        self::assertStringContainsString('"products" is at version 1', $breaks[0]);
        self::assertStringContainsString('its last receipt attests', $breaks[0]);
    }

    public function testWithoutASignerTheLifecycleRunsAndIssuesNoReceipts(): void
    {
        $graph = $this->graph(null);

        $outcome = $graph->planner('memory')->apply(self::schema());

        self::assertSame(Operation::Created, $outcome->operation);
        self::assertNull($outcome->receipt);
        self::assertSame(0, $this->storage->count(Schema::RECEIPT, []));
        $this->expectException(LogicException::class);
        $graph->verifier();
    }

    public function testACreateWhoseReceiptCannotBeIssuedLeavesNothingBehind(): void
    {
        // A broken chain refuses to grow: receipt 1 was issued and then removed.
        $this->storage->insert(Schema::COUNTER, ['name' => 'receipt', 'value' => 1]);

        try {
            $this->graph->planner('memory')->apply(self::schema());
            self::fail('A create went through without its receipt.');
        } catch (LogicException $e) {
            self::assertStringContainsString('Receipt 1 is missing', $e->getMessage());
        }

        self::assertNull($this->engine->inspect(IndexName::of('products_v1')));
        self::assertSame([], $this->graph->registry()->versions(IndexName::of('products')));
        self::assertNull($this->graph->aliases()->current(IndexName::of('products')));
    }

    public function testAnApplyWhoseReceiptCannotBeIssuedKeepsTheOldRegistration(): void
    {
        $this->graph->planner('memory')->apply(self::schema());
        $this->storage->delete(Schema::RECEIPT, ['sequence' => 1]);

        try {
            $this->graph->planner('memory')->apply(IndexSchema::for('products')->text('title', boost: 2.0));
            self::fail('An apply went through without its receipt.');
        } catch (LogicException) {
        }

        self::assertSame(self::schema()->hash(), $this->graph->registry()->find(IndexName::of('products_v1'))?->schema->hash());
    }

    private function graph(?SodiumSigner $signer): Graph
    {
        return Orion::create(new Config(
            storage: $this->storage,
            engines: new EngineRegistry(memory: $this->engine ??= new InMemoryEngine()),
            tenancy: Tenancy::single(),
            clock: new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
            receiptSigner: $signer,
        ))->graph();
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')->text('title');
    }
}
