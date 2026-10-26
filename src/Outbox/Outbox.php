<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;
use Orion\Storage\Condition;
use Orion\Storage\Counters;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Psr\Clock\ClockInterface;

use function array_map;

/**
 * The transactional outbox (v1 §10, docs/spec/revision.md §4). Call {@see index()} and {@see delete()} inside the
 * transaction that writes the entity, on the same {@see Storage}: the entry commits or rolls back with it. Each index
 * keeps one entry per document, the latest, at an offset taken from that index's counter, so offsets are dense and
 * committed in order and a replay from offset 0 rebuilds the index.
 *
 * Writers to one index queue on its counter until they commit. A transaction recording into several indices takes
 * their counters in the order it records; record them in a consistent order, or the database will abort one of two
 * transactions that record the same indices in opposite orders.
 */
final readonly class Outbox
{
    private const string COUNTER = 'outbox:';

    private Counters $counters;

    public function __construct(private Storage $storage, private ClockInterface $clock)
    {
        $this->counters = new Counters($storage);
    }

    /**
     * @param array<string, mixed> $fields the projected document
     */
    public function index(IndexName $index, string $id, array $fields): Entry
    {
        Entry::encode($fields);

        return $this->record($index, $id, EntryKind::Index, $fields);
    }

    public function delete(IndexName $index, string $id): Entry
    {
        return $this->record($index, $id, EntryKind::Delete, null);
    }

    /**
     * The last offset handed out for the index, 0 when nothing was recorded.
     */
    public function head(IndexName $index): int
    {
        return $this->counters->value(self::COUNTER . $index->value);
    }

    /**
     * @return list<Entry> up to `$limit` entries after the offset, in offset order
     */
    public function after(IndexName $index, int $offset, int $limit): array
    {
        $rows = $this->storage->findMany(Schema::OUTBOX, ['index_name' => $index->value, 'outbox_offset' => Condition::gt($offset)], ['outbox_offset' => 'asc'], $limit);

        return array_map(Entry::fromRow(...), $rows);
    }

    public function find(IndexName $index, int $offset): ?Entry
    {
        $row = $this->storage->findOne(Schema::OUTBOX, ['index_name' => $index->value, 'outbox_offset' => $offset]);

        return $row === null ? null : Entry::fromRow($row);
    }

    /**
     * @param array<string, mixed>|null $fields
     */
    private function record(IndexName $index, string $id, EntryKind $kind, ?array $fields): Entry
    {
        Entry::assertId($id);

        return $this->storage->transaction(function () use ($index, $id, $kind, $fields): Entry {
            $entry = new Entry($index, $this->counters->next(self::COUNTER . $index->value), $id, $kind, $fields, $this->clock->now());
            $this->storage->delete(Schema::OUTBOX, ['index_name' => $index->value, 'document_id' => $id]);
            $this->storage->insert(Schema::OUTBOX, $entry->toRow());

            return $entry;
        });
    }
}
