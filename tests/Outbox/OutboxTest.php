<?php

declare(strict_types=1);

namespace Orion\Tests\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use Orion\IndexName;
use Orion\Outbox\Entry;
use Orion\Outbox\EntryKind;
use Orion\Outbox\Outbox;
use Orion\Storage\Schema;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function fopen;
use function str_repeat;

use const NAN;

final class OutboxTest extends TestCase
{
    private InMemoryStorage $storage;
    private Outbox $outbox;
    private IndexName $products;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->outbox = new Outbox($this->storage, new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')));
        $this->products = IndexName::of('products');
    }

    public function testEachRecordTakesTheIndexsNextOffset(): void
    {
        $first = $this->outbox->index($this->products, 'p1', ['title' => 'Lamp', 'price' => 10.0]);
        $second = $this->outbox->index($this->products, 'p2', ['title' => 'Desk']);

        self::assertSame(1, $first->offset);
        self::assertSame(2, $second->offset);
        self::assertSame(2, $this->outbox->head($this->products));
        self::assertSame(EntryKind::Index, $first->kind);
        self::assertSame(['title' => 'Lamp', 'price' => 10.0], $first->fields);
        self::assertSame('2026-10-26 12:00:00', $first->recordedAt->format('Y-m-d H:i:s'));
    }

    public function testOffsetsArePerIndex(): void
    {
        $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->outbox->index($this->products, 'p2', ['title' => 'Desk']);
        $order = $this->outbox->index(IndexName::of('orders'), 'o1', ['total' => 5]);

        self::assertSame(1, $order->offset);
        self::assertSame(0, $this->outbox->head(IndexName::of('customers')));
    }

    public function testRecordingADocumentAgainReplacesItsEntry(): void
    {
        $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);
        $this->outbox->index($this->products, 'p2', ['title' => 'Desk']);
        $this->outbox->index($this->products, 'p1', ['title' => 'Floor lamp']);

        $entries = $this->outbox->after($this->products, 0, 10);

        self::assertSame(2, $this->outbox->size($this->products), 'A replay reads one entry per document.');
        self::assertSame([['p2', 2], ['p1', 3]], array_map(static fn(Entry $e): array => [$e->documentId, $e->offset], $entries));
        self::assertSame(['title' => 'Floor lamp'], $entries[1]->fields);
    }

    public function testADeleteReplacesTheDocumentsWriteWithATombstone(): void
    {
        $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);

        $delete = $this->outbox->delete($this->products, 'p1');

        self::assertSame(EntryKind::Delete, $delete->kind);
        self::assertNull($delete->fields);
        self::assertSame(2, $delete->offset);
        self::assertEquals([$delete], $this->outbox->after($this->products, 0, 10));
        self::assertEquals(new \Orion\Write\Tombstone('p1', 2), $delete->toTombstone());
    }

    public function testAnEntryBecomesADocumentVersionedByItsOffset(): void
    {
        $entry = $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);

        self::assertEquals(new \Orion\Write\Document('p1', ['title' => 'Lamp'], 1), $entry->toDocument());
    }

    public function testATombstoneIsNotADocumentNorAWriteATombstone(): void
    {
        $write = $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);
        $delete = $this->outbox->delete($this->products, 'p2');

        foreach ([static fn() => $delete->toDocument(), static fn() => $write->toTombstone()] as $wrong) {
            try {
                $wrong();
                self::fail('An entry converted to the wrong kind.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testARolledBackRecordLeavesNoEntryAndNoOffset(): void
    {
        try {
            $this->storage->transaction(function (): void {
                $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);

                throw new RuntimeException('the entity write failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->outbox->head($this->products));
        self::assertSame([], $this->outbox->after($this->products, 0, 10));
        self::assertSame(1, $this->outbox->index($this->products, 'p1', ['title' => 'Lamp'])->offset);
    }

    public function testEntriesAreReadInOffsetOrderAfterACursor(): void
    {
        foreach (['p1', 'p2', 'p3', 'p4'] as $id) {
            $this->outbox->index($this->products, $id, ['title' => $id]);
        }

        $page = $this->outbox->after($this->products, 1, 2);

        self::assertSame([2, 3], array_map(static fn(Entry $e): int => $e->offset, $page));
        self::assertSame('p3', $this->outbox->find($this->products, 3)?->documentId);
        self::assertNull($this->outbox->find($this->products, 9));
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    #[DataProvider('invalidRecords')]
    public function testAnInvalidRecordIsRefusedAndNothingIsWritten(string $id, array $fields): void
    {
        try {
            $this->outbox->index($this->products, $id, $fields);
            self::fail('An invalid record was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->storage->count(Schema::OUTBOX, []));
        self::assertSame(0, $this->outbox->head($this->products));
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function invalidRecords(): iterable
    {
        yield 'empty id' => ['', ['title' => 'Lamp']];
        yield 'id longer than the column' => [str_repeat('x', 256), ['title' => 'Lamp']];
        yield 'fields as a list' => ['p1', ['Lamp', 'Desk']];
        yield 'a value JSON cannot hold' => ['p1', ['price' => NAN]];
        yield 'a resource' => ['p1', ['file' => fopen('php://memory', 'r')]];
    }

    public function testAnEntryRoundTripsThroughItsRowAsADriverReturnsIt(): void
    {
        $entry = $this->outbox->index($this->products, 'p1', ['title' => 'Lámpara', 'price' => 10.0, 'tags' => ['a', 'b']]);
        $row = $entry->toRow();
        $driver = ['index_name' => 'products', 'outbox_offset' => '1', 'document_id' => 'p1', 'operation' => 'index', 'payload' => $row['payload'], 'recorded_at' => '2026-10-26 12:00:00.000000'];

        self::assertEquals($entry, Entry::fromRow($row));
        self::assertEquals($entry, Entry::fromRow($driver));
        self::assertSame(10.0, Entry::fromRow($driver)->fields['price'] ?? null);
    }

    public function testAnEntryRowWithAnUnknownColumnOrKindIsRefused(): void
    {
        $row = $this->outbox->index($this->products, 'p1', ['title' => 'Lamp'])->toRow();

        foreach ([[...$row, 'extra' => 1], [...$row, 'operation' => 'upsert'], [...$row, 'operation' => 'delete']] as $bad) {
            try {
                Entry::fromRow($bad);
                self::fail('A bad outbox row was read.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAnEntryPrintsAsData(): void
    {
        $entry = $this->outbox->index($this->products, 'p1', ['title' => 'Lamp']);

        self::assertSame(['index' => 'products', 'offset' => 1, 'document' => 'p1', 'operation' => 'index', 'fields' => ['title' => 'Lamp']], $entry->toArray());
    }
}
