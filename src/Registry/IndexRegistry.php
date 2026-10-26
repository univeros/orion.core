<?php

declare(strict_types=1);

namespace Orion\Registry;

use Orion\IndexName;
use Orion\Storage\Schema;
use Orion\Storage\Storage;

use function array_map;
use function max;

/**
 * The versioned physical indices Orion has built, by alias, on the host's {@see Storage}.
 */
final readonly class IndexRegistry
{
    public function __construct(private Storage $storage)
    {
    }

    /**
     * Inserts the physical index, or replaces what is recorded for it after an in-place apply.
     */
    public function record(IndexRegistration $registration): void
    {
        $row = $registration->toRow();
        if ($this->storage->update(Schema::INDEX, ['name' => $row['name']], $row) === 0) {
            $this->storage->insert(Schema::INDEX, $row);
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

    public function forget(IndexName $physical): void
    {
        $this->storage->delete(Schema::INDEX, ['name' => $physical->value]);
    }
}
