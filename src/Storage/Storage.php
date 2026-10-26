<?php

declare(strict_types=1);

namespace Orion\Storage;

/**
 * Row-level access to the host's database, on the host's live connection (docs/spec/revision.md §4): the outbox
 * row must commit or roll back with the entity write. Rows are `column => value` arrays.
 *
 * Criteria are `column => value` where a scalar means equality, a list means IN (an empty list matches nothing),
 * null means IS NULL, and a {@see Condition} means its comparison; entries are ANDed. `update()` and `delete()`
 * refuse empty criteria, so no call can rewrite or wipe a table (the receipts are append-only). Values crossing
 * this boundary are PHP scalars, null and DateTimeImmutable; adapters bind every value, never interpolate it, and
 * return raw driver values.
 *
 * Table and column names (criteria, row, data and order keys) are developer-supplied constants, never request data.
 * Adapters still check each with {@see Identifier::assert()} before quoting it, accept only `asc` and `desc` as
 * directions, and refuse a negative limit or offset, all with InvalidArgumentException.
 * {@see \Orion\Testing\StorageConformanceTestCase} is the definition of done.
 */
interface Storage
{
    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>|null
     */
    public function findOne(string $table, array $criteria): ?array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'asc'|'desc'>|null $orderBy
     * @return list<array<string, mixed>>
     */
    public function findMany(string $table, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * @param array<string, mixed> $row
     * @throws DuplicateKey when the row repeats a primary or unique key
     */
    public function insert(string $table, array $row): void;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $data
     * @return int the rows the criteria matched, changed or not
     */
    public function update(string $table, array $criteria, array $data): int;

    /**
     * @param array<string, mixed> $criteria
     * @return int affected rows
     */
    public function delete(string $table, array $criteria): int;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(string $table, array $criteria): int;

    /**
     * Adds one to an integer column of the single row the criteria match and returns the new value, or null when no
     * row matches. The row stays locked until the surrounding transaction ends, so concurrent callers queue behind it
     * and values are handed out in commit order (the outbox offset, docs/decisions.md WP4). Runs as one atomic step
     * when no transaction is open.
     *
     * @param array<string, mixed> $criteria
     * @throws \LogicException when the criteria match more than one row; nothing is changed
     * @throws \InvalidArgumentException on empty criteria
     */
    public function increment(string $table, array $criteria, string $column): ?int;

    /**
     * Runs `$fn` inside a transaction on the same connection (a savepoint when nested), committing on return and
     * rolling back when it throws.
     *
     * @template R
     * @param callable(): R $fn
     * @return R
     */
    public function transaction(callable $fn): mixed;
}
