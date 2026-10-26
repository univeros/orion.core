<?php

declare(strict_types=1);

namespace Orion\Registry;

use LogicException;
use Orion\IndexName;
use Orion\Storage\DuplicateKey;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Support\Assert;

use function array_map;
use function max;
use function sprintf;

/**
 * The versioned physical indices Orion has built, by alias, on the host's {@see Storage}.
 */
final readonly class IndexRegistry
{
    public function __construct(private Storage $storage)
    {
    }

    /**
     * Claims the physical index: the first registration wins, the database's primary key arbitrating between
     * concurrent callers, so whoever holds the row owns the index and may drop it.
     *
     * @throws LogicException when the physical index is already registered
     */
    public function register(IndexRegistration $registration): void
    {
        if ($this->find($registration->physical) !== null) {
            throw new LogicException(sprintf('%s is already registered.', Assert::show($registration->physical->value)));
        }
        try {
            $this->storage->insert(Schema::INDEX, $registration->toRow());
        } catch (DuplicateKey $raced) {
            throw new LogicException(sprintf('%s is already registered.', Assert::show($registration->physical->value)), 0, $raced);
        }
    }

    /**
     * Replaces what is recorded for a registered physical index, after an in-place apply.
     *
     * @throws LogicException when the physical index is not registered
     */
    public function update(IndexRegistration $registration): void
    {
        $row = $registration->toRow();
        if ($this->storage->update(Schema::INDEX, ['name' => $row['name']], $row) === 0) {
            throw new LogicException(sprintf('%s is not registered.', Assert::show($registration->physical->value)));
        }
    }

    public function find(IndexName $physical): ?IndexRegistration
    {
        $row = $this->storage->findOne(Schema::INDEX, ['name' => $physical->value]);

        return $row === null ? null : IndexRegistration::fromRow($row);
    }

    /**
     * @return list<IndexRegistration> oldest version first
     */
    public function versions(IndexName $alias): array
    {
        return array_map(IndexRegistration::fromRow(...), $this->storage->findMany(Schema::INDEX, ['alias' => $alias->value], ['version' => 'asc']));
    }

    public function nextVersion(IndexName $alias): int
    {
        $versions = $this->versions($alias);

        return $versions === [] ? 1 : max(array_map(static fn(IndexRegistration $r): int => $r->version, $versions)) + 1;
    }

    /**
     * @return bool whether the physical index was registered
     */
    public function forget(IndexName $physical): bool
    {
        return $this->storage->delete(Schema::INDEX, ['name' => $physical->value]) > 0;
    }
}
