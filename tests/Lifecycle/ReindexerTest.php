<?php

declare(strict_types=1);

namespace Orion\Tests\Lifecycle;

use DateTimeImmutable;
use LogicException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Lifecycle\ReindexJob;
use Orion\Orion;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\EngineKilled;
use Orion\Testing\FaultyEngine;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableClock;
use Orion\Tests\Support\RecordingMetrics;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function array_map;
use function ksort;
use function str_repeat;

final class ReindexerTest extends TestCase
{
    private InMemoryStorage $storage;
    private FaultyEngine $memory;
    private FaultyEngine $native;
    private SettableClock $clock;
    private RecordingMetrics $metrics;
    private Graph $graph;
    private IndexName $products;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->graph = Orion::create(new Config(
            storage: $this->storage,
            engines: new EngineRegistry(
                memory: $this->memory = new FaultyEngine(new InMemoryEngine()),
                native: $this->native = new FaultyEngine(new AliasFakeEngine()),
            ),
            tenancy: Tenancy::single(),
            clock: $this->clock = new SettableClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
            metrics: $this->metrics = new RecordingMetrics(),
            receiptSigner: SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1'),
        ))->graph();
        $this->products = IndexName::of('products');
    }

    public function testAReindexBuildsTheNextVersionByReplayAndSwapsTheAlias(): void
    {
        $this->seed('memory', 5);

        $result = $this->graph->reindexer('memory')->reindex(self::rebuilt());

        self::assertSame('products_v2', $this->graph->aliases()->current($this->products)?->physical->value);
        self::assertSame(self::titles(5), $this->documents($this->memory, 'products_v2'));
        self::assertSame(self::titles(5), $this->documents($this->memory, 'products_v1'), 'The old version stays until it is pruned.');
        self::assertSame(['index' => 'products', 'from_version' => 1, 'to_version' => 2, 'physical' => 'products_v2', 'documents' => 5, 'to_offset' => 5], \array_slice($result->toArray(), 0, 6));
        self::assertSame($result->receipt?->toArray(), $result->toArray()['receipt']);
        self::assertSame([Operation::Reindexed, 1, 2, 5, 0, 5, self::rebuilt()->hash()], [$result->receipt?->operation, $result->receipt?->fromVersion, $result->receipt?->toVersion, $result->receipt?->documentCount, $result->receipt?->fromOffset, $result->receipt?->toOffset, $result->receipt?->schemaHash]);
        self::assertSame(0, $this->storage->count(Schema::REINDEX, []), 'The lock is released.');
        self::assertTrue($this->graph->verifier()->verify()->ok());
        self::assertSame(5.0, $this->metrics->counters['orion.reindex.replayed|index=products']);
    }

    public function testADeletedDocumentIsNotResurrected(): void
    {
        $this->seed('memory', 3);
        $this->graph->outbox()->delete($this->products, 'p2');

        $this->graph->reindexer('memory')->reindex(self::rebuilt());

        self::assertSame(['p1' => 'Title 1', 'p3' => 'Title 3'], $this->documents($this->memory, 'products_v2'));
    }

    public function testWritesRecordedDuringTheReplayReachTheNewVersion(): void
    {
        $this->seed('memory', 5);
        $this->memory->onBulk(function (IndexName $index, int $call): void {
            if ($index->value === 'products_v2' && $call === 1) {
                $this->graph->outbox()->index($this->products, 'p1', ['title' => 'Changed']);
                $this->graph->outbox()->delete($this->products, 'p5');
                $this->graph->outbox()->index($this->products, 'p6', ['title' => 'Title 6']);
                $this->graph->drain()->run($this->products);
            }
        });

        $result = $this->graph->reindexer('memory')->reindex(self::rebuilt(), batch: 2);

        self::assertSame(['p1' => 'Changed', 'p2' => 'Title 2', 'p3' => 'Title 3', 'p4' => 'Title 4', 'p6' => 'Title 6'], $this->documents($this->memory, 'products_v2'));
        self::assertSame(8, $result->toOffset);
    }

    public function testWritesRecordedBetweenTheLastReadAndTheSwapAreCaughtUpAfterIt(): void
    {
        $this->seed('memory', 5);
        // Batches of 2 read p1-p2, p3-p4, then p5 alone: the short read ends the replay, and its write is the last before the swap.
        $this->memory->onBulk(function (IndexName $index, int $call): void {
            if ($index->value === 'products_v2' && $call === 3) {
                $this->memory->onBulk(null);
                $this->graph->outbox()->index($this->products, 'p6', ['title' => 'Title 6']);
                $this->graph->outbox()->delete($this->products, 'p1');
            }
        });

        $this->graph->reindexer('memory')->reindex(self::rebuilt(), batch: 2);

        self::assertSame(['p2' => 'Title 2', 'p3' => 'Title 3', 'p4' => 'Title 4', 'p5' => 'Title 5', 'p6' => 'Title 6'], $this->documents($this->memory, 'products_v2'));
    }

    public function testAReindexKilledMidReplayResumesWhereItStoppedAndLosesNothing(): void
    {
        $this->seed('memory', 5);
        $this->memory->onBulk(function (IndexName $index, int $call): void {
            if ($index->value === 'products_v2' && $call === 2) {
                $this->memory->onBulk(null);
                $this->memory->killDuringBulk(documents: 1);
            }
        });

        $this->assertKilled(fn() => $this->graph->reindexer('memory')->reindex(self::rebuilt(), batch: 2));
        self::assertSame('products_v1', $this->graph->aliases()->current($this->products)?->physical->value);
        self::assertSame(2, $this->graph->reindexer('memory')->pending($this->products)?->position);

        $result = $this->graph->reindexer('memory')->reindex(self::rebuilt(), batch: 2);

        self::assertSame(self::titles(5), $this->documents($this->memory, 'products_v2'));
        self::assertSame(5, $result->documents, 'The receipt counts the replay as an uninterrupted run would.');
        self::assertSame(1, $this->storage->count(Schema::RECEIPT, ['operation' => 'reindexed']));
        self::assertTrue($this->graph->verifier()->verify()->ok());
    }

    public function testAReindexKilledBeforeTheEngineSwapsTheAliasResumes(): void
    {
        $this->seed('native', 3);
        $this->native->killBefore(FaultyEngine::ALIAS);

        $this->assertKilled(fn() => $this->graph->reindexer('native')->reindex(self::rebuilt()));
        self::assertSame('products_v1', $this->graph->aliases()->current($this->products)?->physical->value);

        $this->graph->reindexer('native')->reindex(self::rebuilt());

        self::assertSame('products_v2', $this->graph->aliases()->current($this->products)->physical->value);
        self::assertSame(self::titles(3), $this->documents($this->native, 'products'));
    }

    public function testAReindexWhoseSwapTheEngineMadeButNeverAcknowledgedResumes(): void
    {
        $this->seed('native', 3);
        $this->native->killAfter(FaultyEngine::ALIAS);

        $this->assertKilled(fn() => $this->graph->reindexer('native')->reindex(self::rebuilt()));
        // The engine moved its alias; the row did not commit. Writes follow the engine's alias meanwhile.
        $this->graph->outbox()->index($this->products, 'p4', ['title' => 'Title 4']);
        $this->graph->drain()->run($this->products);

        $this->graph->reindexer('native')->reindex(self::rebuilt());

        self::assertSame('products_v2', $this->graph->aliases()->current($this->products)?->physical->value);
        self::assertSame(self::titles(4), $this->documents($this->native, 'products'));
        self::assertTrue($this->graph->verifier()->verify()->ok());
    }

    public function testAReindexHeldByAnotherProcessIsRefusedUntilItsLeaseExpires(): void
    {
        $this->seed('memory', 2);
        $this->crashedReindex(self::rebuilt(), leaseSecondsLeft: 60);

        try {
            $this->graph->reindexer('memory')->reindex(self::rebuilt());
            self::fail('Two reindexes of one alias ran at once.');
        } catch (LogicException $e) {
            self::assertStringContainsString('in progress', $e->getMessage());
        }
        $this->clock->advance('+61 seconds');

        $result = $this->graph->reindexer('memory')->reindex(self::rebuilt());

        self::assertSame(2, $result->to->version);
        self::assertSame(self::titles(2), $this->documents($this->memory, 'products_v2'));
    }

    public function testAnInterruptedReindexToAnotherSchemaMustBeAbortedFirst(): void
    {
        $this->seed('memory', 2);
        $this->crashedReindex(self::rebuilt(), leaseSecondsLeft: -1);
        $other = IndexSchema::for('products')->text('title', language: 'fr');

        try {
            $this->graph->reindexer('memory')->reindex($other);
            self::fail('A pending reindex was silently replaced.');
        } catch (LogicException $e) {
            self::assertStringContainsString('abort', $e->getMessage());
        }

        $aborted = $this->graph->reindexer('memory')->abort($this->products);
        $result = $this->graph->reindexer('memory')->reindex($other);

        self::assertSame('products_v2', $aborted?->physical->value);
        self::assertSame($other->hash(), $result->to->schema->hash());
        self::assertSame(self::titles(2), $this->documents($this->memory, 'products_v2'));
    }

    public function testAbortIsRefusedWhileTheLeaseIsHeldAndANoOpWithoutAReindex(): void
    {
        $this->seed('memory', 1);
        self::assertNull($this->graph->reindexer('memory')->abort($this->products));
        $this->crashedReindex(self::rebuilt(), leaseSecondsLeft: 60);

        $this->expectException(LogicException::class);

        $this->graph->reindexer('memory')->abort($this->products);
    }

    public function testApplyIsRefusedWhileAReindexIsPending(): void
    {
        $this->seed('memory', 1);
        $this->crashedReindex(self::rebuilt(), leaseSecondsLeft: 60);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reindex');

        $this->graph->planner('memory')->apply(self::schema()->keyword('sku'));
    }

    public function testAnAliasOrionHasNotBuiltCannotBeReindexed(): void
    {
        $this->expectException(LogicException::class);

        $this->graph->reindexer('memory')->reindex(self::rebuilt());
    }

    public function testAReindexOnTheWrongEngineIsRefused(): void
    {
        $this->seed('memory', 1);

        $this->expectException(LogicException::class);

        $this->graph->reindexer('native')->reindex(self::rebuilt());
    }

    public function testTheDrainWritesToTheNewVersionOnceSwapped(): void
    {
        $this->seed('memory', 1);
        $this->graph->reindexer('memory')->reindex(self::rebuilt());

        $this->graph->outbox()->index($this->products, 'p2', ['title' => 'Title 2']);
        $this->graph->drain()->run($this->products);

        self::assertSame(self::titles(2), $this->documents($this->memory, 'products_v2'));
        self::assertSame(self::titles(1), $this->documents($this->memory, 'products_v1'));
    }

    public function testAnEntryTheDrainReadsAfterASwapReachesTheNewVersion(): void
    {
        $this->graph->planner('memory')->apply(self::schema());
        for ($i = 1; $i <= 5; ++$i) {
            $this->graph->outbox()->index($this->products, 'p' . $i, ['title' => 'Title ' . $i]);
        }
        // While the drain writes its first batch to version 1, a whole reindex runs and swaps, and then p6 commits:
        // the drain's next read (offsets 4 to 6) sees p6, which no replay will, so the drain must send it to version 2.
        $this->memory->onBulk(function (IndexName $index, int $call): void {
            if ($index->value === 'products_v1' && $call === 1) {
                $this->memory->onBulk(null);
                $this->graph->reindexer('memory')->reindex(self::rebuilt());
                $this->graph->outbox()->index($this->products, 'p6', ['title' => 'Title 6']);
            }
        });

        $this->graph->drain()->run($this->products, batch: 3);

        self::assertSame(self::titles(6), $this->documents($this->memory, 'products_v2'));
    }

    public function testPruneDropsReplacedVersionsOnlyAfterTheGraceWindow(): void
    {
        $this->seed('memory', 1);
        $this->graph->reindexer('memory')->reindex(self::rebuilt());

        self::assertSame([], $this->graph->reindexer('memory')->prune($this->products, graceSeconds: 300));
        $this->clock->advance('+301 seconds');
        $dropped = $this->graph->reindexer('memory')->prune($this->products, graceSeconds: 300);

        self::assertSame(['products_v1'], array_map(static fn($r): string => $r->physical->value, $dropped));
        self::assertNull($this->memory->inspect(IndexName::of('products_v1')));
        self::assertSame([2], array_map(static fn($r): int => $r->version, $this->graph->registry()->versions($this->products)));
        $receipt = $this->graph->receipts()->latest();
        self::assertSame([Operation::Dropped, 1], [$receipt?->operation, $receipt?->toVersion]);
        self::assertSame([], $this->graph->reindexer('memory')->prune($this->products, graceSeconds: 300));
        self::assertTrue($this->graph->verifier()->verify()->ok());
    }

    public function testPruneKeepsTheVersionBeingBuilt(): void
    {
        $this->seed('memory', 1);
        $this->crashedReindex(self::rebuilt(), leaseSecondsLeft: 60);
        $this->clock->advance('+1 hour');

        self::assertSame([], $this->graph->reindexer('memory')->prune($this->products, graceSeconds: 0));
        self::assertNotNull($this->memory->inspect(IndexName::of('products_v2')));
    }

    public function testAJobRoundTripsThroughItsRow(): void
    {
        $this->seed('memory', 1);
        $job = $this->crashedReindex(self::rebuilt(), leaseSecondsLeft: 60);
        $driver = [...array_map(static fn(mixed $v): mixed => \is_int($v) ? (string) $v : $v, $job->toRow()), 'lease_until' => '2026-10-26 12:01:00.000000', 'started_at' => '2026-10-26 12:00:00'];

        self::assertEquals($job, ReindexJob::fromRow($job->toRow()));
        self::assertEquals($job, ReindexJob::fromRow($driver));
        $this->expectException(\InvalidArgumentException::class);
        ReindexJob::fromRow([...$job->toRow(), 'extra' => 1]);
    }

    /**
     * A reindex claimed by a process that died: version 2 registered and created, nothing replayed, the lease running.
     */
    private function crashedReindex(IndexSchema $schema, int $leaseSecondsLeft): ReindexJob
    {
        $now = $this->clock->now();
        $registration = new \Orion\Registry\IndexRegistration(IndexName::of('products_v2'), $this->products, 'memory', 2, $schema, $now);
        $this->graph->registry()->register($registration);
        $this->memory->ensure($schema->named($registration->physical));
        $job = new ReindexJob($this->products, 'memory', $registration->physical, 1, 0, 0, 'dead-process', $now->modify(($leaseSecondsLeft >= 0 ? '+' : '') . $leaseSecondsLeft . ' seconds'), $now);
        $this->storage->insert(Schema::REINDEX, $job->toRow());

        return $job;
    }

    private function seed(string $engine, int $documents): void
    {
        $this->graph->planner($engine)->apply(self::schema());
        for ($i = 1; $i <= $documents; ++$i) {
            $this->graph->outbox()->index($this->products, 'p' . $i, ['title' => 'Title ' . $i]);
        }
        $this->graph->drain()->run($this->products);
    }

    private function assertKilled(callable $run): void
    {
        try {
            $run();
            self::fail('The run survived a killed engine.');
        } catch (EngineKilled) {
            self::addToAssertionCount(1);
        }
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')->text('title');
    }

    /**
     * A breaking change: the analysis of `title`.
     */
    private static function rebuilt(): IndexSchema
    {
        return IndexSchema::for('products')->text('title', language: 'en');
    }

    /**
     * @return array<string, string>
     */
    private static function titles(int $count): array
    {
        $titles = [];
        for ($i = 1; $i <= $count; ++$i) {
            $titles['p' . $i] = 'Title ' . $i;
        }

        return $titles;
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(FaultyEngine $engine, string $index): array
    {
        $documents = [];
        foreach ($engine->raw(IndexName::of($index), static fn(): bool => true)->hits as $hit) {
            $documents[$hit->id] = $hit->source['title'] ?? null;
        }
        ksort($documents);

        return $documents;
    }
}
