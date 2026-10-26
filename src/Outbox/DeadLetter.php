<?php

declare(strict_types=1);

namespace Orion\Outbox;

use DateTimeImmutable;
use Orion\IndexName;
use Orion\Support\Assert;
use Orion\Support\Input;
use Orion\Support\Row;

/**
 * An outbox entry the engine refused, kept for retry (v1 §10). Persisted as a row of `orion_dead_letter`;
 * {@see fromRow()} is the inverse of {@see toRow()} and refuses unknown columns.
 */
final readonly class DeadLetter
{
    private const array COLUMNS = ['index_name', 'outbox_offset', 'document_id', 'error', 'attempts', 'failed_at'];

    public function __construct(
        public IndexName $index,
        public int $offset,
        public string $documentId,
        public string $error,
        public int $attempts,
        public DateTimeImmutable $failedAt,
    ) {
        Assert::positive($offset, 'An outbox offset');
        Entry::assertId($documentId);
        Assert::notEmpty($error, 'A dead letter\'s error');
        Assert::positive($attempts, 'A dead letter\'s attempts');
    }

    /**
     * @return array{index_name: string, outbox_offset: int, document_id: string, error: string, attempts: int, failed_at: DateTimeImmutable}
     */
    public function toRow(): array
    {
        return [
            'index_name' => $this->index->value,
            'outbox_offset' => $this->offset,
            'document_id' => $this->documentId,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'failed_at' => $this->failedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row raw driver values
     */
    public static function fromRow(array $row): self
    {
        $path = 'orion_dead_letter';
        $row = Input::object($row, $path, self::COLUMNS);

        return new self(
            IndexName::of(Input::string($row['index_name'], $path . '.index_name')),
            Row::int($row['outbox_offset'], $path . '.outbox_offset'),
            Input::string($row['document_id'], $path . '.document_id'),
            Input::string($row['error'], $path . '.error'),
            Row::int($row['attempts'], $path . '.attempts'),
            Row::date($row['failed_at'], $path . '.failed_at'),
        );
    }

    /**
     * @return array{index: string, offset: int, document: string, error: string, attempts: int}
     */
    public function toArray(): array
    {
        return ['index' => $this->index->value, 'offset' => $this->offset, 'document' => $this->documentId, 'error' => $this->error, 'attempts' => $this->attempts];
    }
}
