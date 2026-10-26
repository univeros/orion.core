<?php

declare(strict_types=1);

namespace Orion\Tests\Outbox;

use DateTimeImmutable;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Orion;
use Orion\Outbox\DeadLetter;
use Orion\Outbox\SyncDispatcher;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\EngineKilled;
use Orion\Testing\FaultyEngine;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableClock;
use Orion\Testing\AliasingEngine;
use Orion\Tests\Support\RecordingMetrics;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use Orion\Write\Document;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;

final class DrainTest extends TestCase
{
    private InMemoryStorage $storage;
    private FaultyEngine $engine;
    private RecordingMetrics $metrics;
    private SettableClock $clock;
    private Graph $graph;
    private IndexName $products;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->engine = new FaultyEngine(new InMemoryEngine());
        $this->graph = Orion::create(new Config(
            storage: $this->storage,
            engines: new EngineRegistry(memory: $this->engine, native: new AliasingEngine()),
            tenancy: Tenancy::single(),
            clock: $this->clock = new SettableClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
            metrics: $this->metrics = new RecordingMetrics(),
        ))->graph();
        $this->products = IndexName::of('products');
    }

    public function testTheDrainWritesRecordedEntriesToTheIndexBehindTheAlias(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk']);

        $result = $this->graph->drain()->run($this->products);

        self::assertSame(['index' => 'products', 'from' => 0, 'to' => 2, 'head' => 2, 'lag' => 0, 'applied' => 2, 'stale' => 0, 'failed' => 0], $result->toArray());
        self::assertSame(['p1' => 'Lamp', 'p2' => 'Desk'], $this->titles());
    }

    public function testDrainingAgainWritesNothing(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->graph->drain()->run($this->products);

        $again = $this->graph->drain()->run($this->products);

        self::assertSame(0, $again->applied);
        self::assertSame(1, $again->to);
        self::assertSame(1, $this->engine->calls(FaultyEngine::INDEX));
    }

    public function testADeleteIsDrainedAsATombstone(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk']);
        $this->graph->drain()->run($this->products);

        $this->graph->outbox()->delete($this->products, 'p1');
        $result = $this->graph->drain()->run($this->products);

        self::assertSame(1, $result->applied);
        self::assertSame(['p2' => 'Desk'], $this->titles());
    }

    public function testAnIndexNotYetBuiltWaitsAndReportsItsLag(): void
    {
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);

        $result = $this->graph->drain()->run($this->products);

        self::assertSame(['index' => 'products', 'from' => 0, 'to' => 0, 'head' => 1, 'lag' => 1, 'applied' => 0, 'stale' => 0, 'failed' => 0], $result->toArray());
    }

    public function testEntriesRecordedBeforeTheIndexWasBuiltAreDrainedOnceItIs(): void
    {
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->build();

        $this->graph->drain()->run($this->products);

        self::assertSame(['p1' => 'Lamp'], $this->titles());
    }

    public function testTheDrainWorksInBatches(): void
    {
        $this->build();
        foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $id) {
            $this->graph->outbox()->index($this->products, $id, ['title' => $id]);
        }

        $result = $this->graph->drain()->run($this->products, batch: 2);

        self::assertSame(5, $result->applied);
        self::assertSame(3, $this->engine->calls(FaultyEngine::INDEX));
    }

    public function testADocumentTheEngineRejectsGoesToTheDeadLettersAndTheRestAreWritten(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'red']);

        $result = $this->graph->drain()->run($this->products);

        self::assertSame([1, 1, 2], [$result->applied, $result->failed, $result->to]);
        self::assertSame(['p1' => 'Lamp'], $this->titles());
        $letters = $this->graph->deadLetters()->of($this->products);
        self::assertCount(1, $letters);
        self::assertSame(['p2', 2, 1], [$letters[0]->documentId, $letters[0]->offset, $letters[0]->attempts]);
        self::assertStringContainsString('colour', $letters[0]->error);
    }

    public function testRetryingADeadLetterThatStillFailsCountsTheAttempt(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'red']);
        $this->graph->drain()->run($this->products);
        $this->clock->advance('+1 minute');

        $retried = $this->graph->deadLetters()->retry($this->products);

        self::assertSame(['index' => 'products', 'applied' => 0, 'stale' => 0, 'failed' => 1, 'superseded' => 0], $retried->toArray());
        $letter = $this->graph->deadLetters()->of($this->products)[0];
        self::assertSame(2, $letter->attempts);
        self::assertSame('2026-10-26 12:01:00', $letter->failedAt->format('Y-m-d H:i:s'));
    }

    public function testRetryingADeadLetterTheEngineNowAcceptsClearsIt(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'red']);
        $this->graph->drain()->run($this->products);
        $this->graph->planner('memory')->apply(self::schema()->keyword('colour'));

        $retried = $this->graph->deadLetters()->retry($this->products);

        self::assertSame(1, $retried->applied);
        self::assertSame([], $this->graph->deadLetters()->of($this->products));
        self::assertSame(['p2' => 'Desk'], $this->titles());
    }

    public function testANewerEntryForTheDocumentSupersedesItsDeadLetter(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'red']);
        $this->graph->drain()->run($this->products);

        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk']);
        $this->graph->drain()->run($this->products);

        self::assertSame([], $this->graph->deadLetters()->of($this->products));
        self::assertSame(0, $this->graph->deadLetters()->count());
    }

    public function testAnOlderEntryWrittenByAConcurrentDrainKeepsTheDeadLetterOfTheNewerOne(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        // Drain A has read p1 at offset 1; before it writes, p1 is rewritten with a field the index refuses and a second
        // drain dead-letters that newer entry. A then writes its older p1 and must not clear the newer letter.
        $this->engine->beforeNext(FaultyEngine::INDEX, function (): void {
            $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp', 'colour' => 'red']);
            $this->graph->drain()->run($this->products);
        });

        $this->graph->drain()->run($this->products);

        self::assertSame([2], array_map(static fn(DeadLetter $l): int => $l->offset, $this->graph->deadLetters()->of($this->products)));
    }

    public function testRetryingADeadLetterWhoseEntryWasCompactedAwayForgetsIt(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'red']);
        $this->graph->drain()->run($this->products);
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'blue']);

        $retried = $this->graph->deadLetters()->retry($this->products);

        self::assertSame(1, $retried->superseded);
        self::assertSame([], $this->graph->deadLetters()->of($this->products));
    }

    public function testAnEngineReasonIsStoredWithoutControlCharacters(): void
    {
        $this->build();
        $entry = $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);

        $this->graph->deadLetters()->record($entry, "refused\x00 by\tthe\nengine\x1B[31m");

        self::assertSame('refused by the engine [31m', $this->graph->deadLetters()->of($this->products)[0]->error);
    }

    public function testADeadLetterRoundTripsThroughItsRow(): void
    {
        $letter = new DeadLetter($this->products, 7, 'p2', 'rejected', 2, new DateTimeImmutable('2026-10-26 12:00:00+00:00'));
        $driver = ['index_name' => 'products', 'outbox_offset' => '7', 'document_id' => 'p2', 'error' => 'rejected', 'attempts' => '2', 'failed_at' => '2026-10-26 12:00:00.000000'];

        self::assertEquals($letter, DeadLetter::fromRow($letter->toRow()));
        self::assertEquals($letter, DeadLetter::fromRow($driver));
        $this->expectException(\InvalidArgumentException::class);
        DeadLetter::fromRow([...$driver, 'extra' => 1]);
    }

    public function testAnEngineKilledMidBulkLosesNothing(): void
    {
        $this->build();
        foreach (['p1', 'p2', 'p3'] as $id) {
            $this->graph->outbox()->index($this->products, $id, ['title' => $id]);
        }
        $this->engine->killDuringBulk(documents: 1);

        try {
            $this->graph->drain()->run($this->products);
            self::fail('The drain survived a killed engine.');
        } catch (EngineKilled) {
        }
        self::assertSame(0, $this->graph->drain()->status($this->products)->to);

        $result = $this->graph->drain()->run($this->products);

        self::assertSame(['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p3'], $this->titles());
        self::assertSame(3, $result->to);
        self::assertSame(2, $result->applied);
        self::assertSame(1, $result->stale);
    }

    public function testOnAnEngineWithNativeAliasesTheDrainWritesThroughTheAlias(): void
    {
        $this->graph->planner('native')->apply(self::schema());
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);

        $this->graph->drain()->run($this->products);

        self::assertSame(['p1'], array_map(static fn($hit): string => $hit->id, $this->graph->engines()->get('native')->raw(IndexName::of('products_v1'), static fn(): bool => true)->hits));
    }

    public function testTheDrainCountsItsWorkAndReportsLag(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Desk', 'colour' => 'red']);

        $this->graph->drain()->run($this->products);

        self::assertSame(1.0, $this->metrics->counters['orion.outbox.applied|index=products']);
        self::assertSame(1.0, $this->metrics->counters['orion.outbox.failed|index=products']);
        self::assertSame(0.0, $this->metrics->gauges['orion.outbox.lag|index=products']);
    }

    public function testAStaleWriteIsCountedNotFailed(): void
    {
        $this->build();
        $this->engine->index(IndexName::of('products_v1'), new Document('p1', ['title' => 'Newer'], 99));
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);

        $result = $this->graph->drain()->run($this->products);

        self::assertSame([0, 1, 0], [$result->applied, $result->stale, $result->failed]);
        self::assertSame(['p1' => 'Newer'], $this->titles());
    }

    public function testDrainingEveryIndexCoversEveryAlias(): void
    {
        $this->build();
        $this->graph->planner('memory')->apply(IndexSchema::for('orders')->keyword('status'));
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->graph->outbox()->index(IndexName::of('orders'), 'o1', ['status' => 'paid']);

        $results = $this->graph->drain()->runAll();

        $indices = array_map(static fn($r): string => $r->index->value, $results);
        sort($indices);
        self::assertSame(['orders', 'products'], $indices);
        self::assertSame(0, $this->storage->count(Schema::DEAD_LETTER, []));
    }

    public function testTheDefaultDispatcherDrainsAtOnce(): void
    {
        $this->build();
        $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Lamp']);

        self::assertInstanceOf(SyncDispatcher::class, $this->graph->dispatcher());
        $this->graph->dispatcher()->dispatch($this->products);

        self::assertSame(['p1' => 'Lamp'], $this->titles());
    }

    private function build(): void
    {
        $this->graph->planner('memory')->apply(self::schema());
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')->text('title');
    }

    /**
     * @return array<string, mixed>
     */
    private function titles(): array
    {
        $titles = [];
        foreach ($this->engine->raw(IndexName::of('products_v1'), static fn(): bool => true)->hits as $hit) {
            $titles[$hit->id] = $hit->source['title'] ?? null;
        }

        return $titles;
    }
}
