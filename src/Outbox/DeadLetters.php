<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;
use Orion\Lifecycle\AliasManager;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Orion\Support\Row;
use Psr\Clock\ClockInterface;

use function array_map;

/**
 * Outbox entries the engine refused, one letter per entry, for retry (v1 §10). A letter is cleared when its entry
 * is finally written, and when a newer entry for the same document is: what matters is the document's latest state.
 */
final readonly class DeadLetters
{
    /** The longest engine reason kept, in bytes. */
    private const int MAX_ERROR_BYTES = 2000;

    public function __construct(
        private Storage $storage,
        private Outbox $outbox,
        private AliasManager $aliases,
        private Delivery $delivery,
        private ClockInterface $clock,
    ) {
    }

    public function record(Entry $entry, string $error): void
    {
        $key = ['index_name' => $entry->index->value, 'outbox_offset' => $entry->offset];
        $error = Assert::truncate($error === '' ? 'The engine gave no reason.' : $error, self::MAX_ERROR_BYTES);
        $existing = $this->storage->findOne(Schema::DEAD_LETTER, $key);
        $attempts = $existing === null ? 1 : Row::int($existing['attempts'], Schema::DEAD_LETTER . '.attempts') + 1;
        $letter = new DeadLetter($entry->index, $entry->offset, $entry->documentId, $error, $attempts, $this->clock->now());
        if ($existing === null) {
            $this->storage->insert(Schema::DEAD_LETTER, $letter->toRow());
        } else {
            $this->storage->update(Schema::DEAD_LETTER, $key, $letter->toRow());
        }
    }

    /**
     * Drops the letters of documents whose latest entry is now in the index.
     *
     * @param list<string> $documentIds
     */
    public function clear(IndexName $index, array $documentIds): void
    {
        if ($documentIds !== []) {
            $this->storage->delete(Schema::DEAD_LETTER, ['index_name' => $index->value, 'document_id' => $documentIds]);
        }
    }

    /**
     * @return list<DeadLetter> oldest entry first
     */
    public function of(IndexName $index, ?int $limit = null): array
    {
        return array_map(DeadLetter::fromRow(...), $this->storage->findMany(Schema::DEAD_LETTER, ['index_name' => $index->value], ['outbox_offset' => 'asc'], $limit));
    }

    public function count(?IndexName $index = null): int
    {
        return $this->storage->count(Schema::DEAD_LETTER, $index === null ? [] : ['index_name' => $index->value]);
    }

    /**
     * Sends each letter's entry again to the index behind the alias.
     */
    public function retry(IndexName $index, int $limit = Drain::DEFAULT_BATCH): Retried
    {
        Assert::positive($limit, 'A retry limit');
        $current = $this->aliases->current($index);
        if ($current === null) {
            return new Retried($index, 0, 0, 0, 0);
        }
        $entries = [];
        $superseded = 0;
        foreach ($this->of($index, $limit) as $letter) {
            $entry = $this->outbox->find($index, $letter->offset);
            if ($entry === null) {
                $this->storage->delete(Schema::DEAD_LETTER, ['index_name' => $index->value, 'outbox_offset' => $letter->offset]);
                ++$superseded;
            } else {
                $entries[] = $entry;
            }
        }
        if ($entries === []) {
            return new Retried($index, 0, 0, 0, $superseded);
        }
        $delivered = $this->delivery->send($current->engine, $this->aliases->targetOf($current), $entries);
        $this->storage->transaction(function () use ($index, $delivered): void {
            foreach ($delivered->failures as $failure) {
                $this->record($failure['entry'], $failure['error']);
            }
            $this->clear($index, $delivered->settled);
        });

        return new Retried($index, $delivered->applied, $delivered->stale, \count($delivered->failures), $superseded);
    }
}
