<?php

declare(strict_types=1);

namespace Orion\Receipt;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Registry\IndexRegistration;
use Orion\Support\Assert;
use Orion\Support\Canonical;
use Orion\Support\Input;
use Orion\Support\Row;

use function base64_encode;
use function hash;
use function preg_match;
use function sprintf;

/**
 * A signed, hash-chained record of one lifecycle operation (v1 §11, docs/spec/revision.md §3): which index, on which
 * engine, from which version to which, with which schema, over which outbox offsets. Its message is the canonical
 * JSON of every field but `hash` and `signature`; `hash` is that message's sha256, `signature` an Ed25519 signature
 * over it (base64), and `previous_hash` the hash of the receipt before it, so editing, removing or reordering a
 * receipt breaks the chain and re-signing needs the key. Persisted as a row of `orion_receipt`; {@see fromRow()} is
 * the inverse of {@see toRow()} and refuses unknown columns.
 */
final readonly class Receipt
{
    private const array COLUMNS = [
        'sequence', 'operation', 'index_name', 'engine', 'from_version', 'to_version', 'document_count', 'schema_hash',
        'from_offset', 'to_offset', 'previous_hash', 'hash', 'kid', 'signature', 'recorded_at',
    ];
    private const string TIME = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public int $sequence,
        public Operation $operation,
        public IndexName $index,
        public string $engine,
        public ?int $fromVersion,
        public int $toVersion,
        public int $documentCount,
        public string $schemaHash,
        public int $fromOffset,
        public int $toOffset,
        public ?string $previousHash,
        public string $hash,
        public string $kid,
        public string $signature,
        public DateTimeImmutable $recordedAt,
    ) {
        Assert::positive($sequence, 'A receipt sequence');
        Assert::notEmpty($engine, 'A receipt engine');
        Assert::positive($toVersion, 'A receipt to_version');
        if ($fromVersion !== null) {
            Assert::positive($fromVersion, 'A receipt from_version');
        }
        foreach (['document_count' => $documentCount, 'from_offset' => $fromOffset, 'to_offset' => $toOffset] as $what => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('A receipt %s cannot be negative, %d given.', $what, $value));
            }
        }
        foreach (['schema_hash' => $schemaHash, 'hash' => $hash, 'previous_hash' => $previousHash ?? $hash] as $what => $value) {
            if (preg_match('/^[0-9a-f]{64}\z/', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('A receipt %s is a sha256 in hex, not %s.', $what, Assert::show($value)));
            }
        }
        Assert::notEmpty($kid, 'A receipt kid');
    }

    /**
     * Hashes and signs the operation as receipt number `$sequence`, chained to `$previousHash`.
     */
    public static function issue(
        int $sequence,
        Operation $operation,
        IndexRegistration $to,
        ?int $fromVersion,
        int $documentCount,
        int $fromOffset,
        int $toOffset,
        ?string $previousHash,
        DateTimeImmutable $at,
        ReceiptSigner $signer,
    ): self {
        $unsigned = new self($sequence, $operation, $to->alias, $to->engine, $fromVersion, $to->version, $documentCount, $to->schema->hash(), $fromOffset, $toOffset, $previousHash, hash('sha256', ''), $signer->kid(), '', $at);
        $message = $unsigned->message();

        return new self($sequence, $operation, $to->alias, $to->engine, $fromVersion, $to->version, $documentCount, $to->schema->hash(), $fromOffset, $toOffset, $previousHash, hash('sha256', $message), $signer->kid(), base64_encode($signer->sign($message)), $at);
    }

    /**
     * What is hashed and signed: every field but the hash and the signature, as canonical JSON.
     */
    public function message(): string
    {
        return Canonical::json([
            'sequence' => $this->sequence,
            'operation' => $this->operation->value,
            'index' => $this->index->value,
            'engine' => $this->engine,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'document_count' => $this->documentCount,
            'schema_hash' => $this->schemaHash,
            'from_offset' => $this->fromOffset,
            'to_offset' => $this->toOffset,
            'previous_hash' => $this->previousHash,
            'kid' => $this->kid,
            'recorded_at' => $this->recordedAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIME),
        ]);
    }

    /**
     * @return array<string, int|string|DateTimeImmutable|null>
     */
    public function toRow(): array
    {
        return [
            'sequence' => $this->sequence,
            'operation' => $this->operation->value,
            'index_name' => $this->index->value,
            'engine' => $this->engine,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'document_count' => $this->documentCount,
            'schema_hash' => $this->schemaHash,
            'from_offset' => $this->fromOffset,
            'to_offset' => $this->toOffset,
            'previous_hash' => $this->previousHash,
            'hash' => $this->hash,
            'kid' => $this->kid,
            'signature' => $this->signature,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row raw driver values
     */
    public static function fromRow(array $row): self
    {
        $path = 'orion_receipt';
        $row = Input::object($row, $path, self::COLUMNS);

        return new self(
            Row::int($row['sequence'], $path . '.sequence'),
            Operation::tryFrom(Input::string($row['operation'], $path . '.operation'))
                ?? throw new InvalidArgumentException(sprintf('%s.operation is not a lifecycle operation.', $path)),
            IndexName::of(Input::string($row['index_name'], $path . '.index_name')),
            Input::string($row['engine'], $path . '.engine'),
            $row['from_version'] === null ? null : Row::int($row['from_version'], $path . '.from_version'),
            Row::int($row['to_version'], $path . '.to_version'),
            Row::int($row['document_count'], $path . '.document_count'),
            Input::string($row['schema_hash'], $path . '.schema_hash'),
            Row::int($row['from_offset'], $path . '.from_offset'),
            Row::int($row['to_offset'], $path . '.to_offset'),
            $row['previous_hash'] === null ? null : Input::string($row['previous_hash'], $path . '.previous_hash'),
            Input::string($row['hash'], $path . '.hash'),
            Input::string($row['kid'], $path . '.kid'),
            Input::string($row['signature'], $path . '.signature'),
            Row::date($row['recorded_at'], $path . '.recorded_at'),
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'operation' => $this->operation->value,
            'index' => $this->index->value,
            'engine' => $this->engine,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'document_count' => $this->documentCount,
            'schema_hash' => $this->schemaHash,
            'from_offset' => $this->fromOffset,
            'to_offset' => $this->toOffset,
            'previous_hash' => $this->previousHash,
            'hash' => $this->hash,
            'kid' => $this->kid,
            'signature' => $this->signature,
            'recorded_at' => $this->recordedAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIME),
        ];
    }
}
