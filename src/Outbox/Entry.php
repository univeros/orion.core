<?php

declare(strict_types=1);

namespace Orion\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Orion\IndexName;
use Orion\Support\Assert;
use Orion\Support\Input;
use Orion\Support\Row;
use Orion\Write\Document;
use Orion\Write\Tombstone;

use function array_is_list;
use function json_encode;
use function sprintf;
use function strlen;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * One row of the outbox: the latest write or delete of a document in an index, at its offset. The offset is the
 * version the engine stores, so a replayed entry and a live one converge on the newest (v1 §8.3). Persisted as a row
 * of `orion_outbox`; {@see fromRow()} is the inverse of {@see toRow()} and refuses unknown columns.
 */
final readonly class Entry
{
    /** The `document_id` column's length. */
    public const int MAX_ID_BYTES = 255;
    private const array COLUMNS = ['index_name', 'outbox_offset', 'document_id', 'operation', 'payload', 'recorded_at'];
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string, mixed>|null $fields the document for an index entry, null for a delete
     */
    public function __construct(
        public IndexName $index,
        public int $offset,
        public string $documentId,
        public EntryKind $kind,
        public ?array $fields,
        public DateTimeImmutable $recordedAt,
    ) {
        Assert::positive($offset, 'An outbox offset');
        self::assertId($documentId);
        if (($kind === EntryKind::Index) !== ($fields !== null)) {
            throw new InvalidArgumentException(sprintf('An index entry carries fields and a delete entry none (%s).', Assert::show($documentId)));
        }
        if ($fields !== null) {
            self::encode($fields);
        }
    }

    public static function assertId(string $id): string
    {
        Assert::notEmpty($id, 'A document id');
        if (strlen($id) > self::MAX_ID_BYTES) {
            throw new InvalidArgumentException(sprintf('A document id is at most %d bytes, %s is longer.', self::MAX_ID_BYTES, Assert::show($id)));
        }

        return $id;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws InvalidArgumentException when the fields are not an object JSON can hold
     */
    public static function encode(array $fields): string
    {
        if ($fields !== [] && array_is_list($fields)) {
            throw new InvalidArgumentException('A document\'s fields are an object of name => value, not a list.');
        }
        try {
            return json_encode($fields, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('A document\'s fields must be JSON: %s.', $exception->getMessage()), 0, $exception);
        }
    }

    public function toDocument(): Document
    {
        if ($this->fields === null) {
            throw new LogicException(sprintf('The entry at offset %d deletes %s; it is not a document.', $this->offset, Assert::show($this->documentId)));
        }

        return new Document($this->documentId, $this->fields, $this->offset);
    }

    public function toTombstone(): Tombstone
    {
        if ($this->kind !== EntryKind::Delete) {
            throw new LogicException(sprintf('The entry at offset %d writes %s; it is not a delete.', $this->offset, Assert::show($this->documentId)));
        }

        return new Tombstone($this->documentId, $this->offset);
    }

    /**
     * @return array{index_name: string, outbox_offset: int, document_id: string, operation: string, payload: string|null, recorded_at: DateTimeImmutable}
     */
    public function toRow(): array
    {
        return [
            'index_name' => $this->index->value,
            'outbox_offset' => $this->offset,
            'document_id' => $this->documentId,
            'operation' => $this->kind->value,
            'payload' => $this->fields === null ? null : self::encode($this->fields),
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row raw driver values
     */
    public static function fromRow(array $row): self
    {
        $path = 'orion_outbox';
        $row = Input::object($row, $path, self::COLUMNS);
        $kind = EntryKind::tryFrom(Input::string($row['operation'], $path . '.operation'))
            ?? throw new InvalidArgumentException(sprintf('%s.operation must be index or delete.', $path));
        /** @var array<string, mixed>|null $fields checked by the constructor */
        $fields = $row['payload'] === null ? null : Row::json($row['payload'], $path . '.payload');

        return new self(
            IndexName::of(Input::string($row['index_name'], $path . '.index_name')),
            Row::int($row['outbox_offset'], $path . '.outbox_offset'),
            Input::string($row['document_id'], $path . '.document_id'),
            $kind,
            $fields,
            Row::date($row['recorded_at'], $path . '.recorded_at'),
        );
    }

    /**
     * @return array{index: string, offset: int, document: string, operation: string, fields: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return ['index' => $this->index->value, 'offset' => $this->offset, 'document' => $this->documentId, 'operation' => $this->kind->value, 'fields' => $this->fields];
    }
}
