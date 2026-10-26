<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Orion\IndexName;
use Orion\Support\Assert;
use Orion\Support\Input;
use Orion\Support\Row;

use function sprintf;
use function strlen;

/**
 * A reindex in progress, and its lock (v1 §8.3): one row of `orion_reindex` per alias. `holder` is the run that owns
 * it until `lease_until`; `position` is the last outbox offset replayed into `physical` and `documents` the writes
 * sent so far, so a run that dies is resumed where it stopped and its receipt counts what an uninterrupted run would.
 * {@see fromRow()} is the inverse of {@see toRow()} and refuses unknown columns.
 */
final readonly class ReindexJob
{
    /** The `holder` column's length. */
    public const int MAX_HOLDER_BYTES = 64;
    private const array COLUMNS = ['alias', 'engine', 'physical', 'from_version', 'position', 'documents', 'holder', 'lease_until', 'started_at'];

    public function __construct(
        public IndexName $alias,
        public string $engine,
        public IndexName $physical,
        public int $fromVersion,
        public int $position,
        public int $documents,
        public string $holder,
        public DateTimeImmutable $leaseUntil,
        public DateTimeImmutable $startedAt,
    ) {
        Assert::notEmpty($engine, 'A reindex engine');
        if (IndexNamer::versionOf($alias, $physical) === null) {
            throw new InvalidArgumentException(sprintf('A reindex of %s builds one of its versions, not %s.', Assert::show($alias->value), Assert::show($physical->value)));
        }
        Assert::positive($fromVersion, 'A reindex from_version');
        Assert::notEmpty($holder, 'A reindex holder');
        Assert::atMost(strlen($holder), self::MAX_HOLDER_BYTES, 'A reindex holder');
        if ($position < 0 || $documents < 0) {
            throw new InvalidArgumentException('A reindex position and document count cannot be negative.');
        }
    }

    /**
     * The same job after a batch: replayed to `$position`, `$sent` more writes, the lease renewed.
     */
    public function advanced(int $position, int $sent, DateTimeImmutable $leaseUntil): self
    {
        return new self($this->alias, $this->engine, $this->physical, $this->fromVersion, $position, $this->documents + $sent, $this->holder, $leaseUntil, $this->startedAt);
    }

    /**
     * The same job, owned by another run until `$leaseUntil`.
     */
    public function heldBy(string $holder, DateTimeImmutable $leaseUntil): self
    {
        return new self($this->alias, $this->engine, $this->physical, $this->fromVersion, $this->position, $this->documents, $holder, $leaseUntil, $this->startedAt);
    }

    /**
     * @return array{alias: string, engine: string, physical: string, from_version: int, position: int, documents: int, holder: string, lease_until: DateTimeImmutable, started_at: DateTimeImmutable}
     */
    public function toRow(): array
    {
        return [
            'alias' => $this->alias->value,
            'engine' => $this->engine,
            'physical' => $this->physical->value,
            'from_version' => $this->fromVersion,
            'position' => $this->position,
            'documents' => $this->documents,
            'holder' => $this->holder,
            'lease_until' => $this->leaseUntil,
            'started_at' => $this->startedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row raw driver values
     */
    public static function fromRow(array $row): self
    {
        $path = 'orion_reindex';
        $row = Input::object($row, $path, self::COLUMNS);

        return new self(
            IndexName::of(Input::string($row['alias'], $path . '.alias')),
            Input::string($row['engine'], $path . '.engine'),
            IndexName::of(Input::string($row['physical'], $path . '.physical')),
            Row::int($row['from_version'], $path . '.from_version'),
            Row::int($row['position'], $path . '.position'),
            Row::int($row['documents'], $path . '.documents'),
            Input::string($row['holder'], $path . '.holder'),
            Row::date($row['lease_until'], $path . '.lease_until'),
            Row::date($row['started_at'], $path . '.started_at'),
        );
    }

    /**
     * @return array{index: string, engine: string, physical: string, from_version: int, position: int, documents: int, lease_until: string}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->alias->value,
            'engine' => $this->engine,
            'physical' => $this->physical->value,
            'from_version' => $this->fromVersion,
            'position' => $this->position,
            'documents' => $this->documents,
            'lease_until' => $this->leaseUntil->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM),
        ];
    }
}
