<?php

declare(strict_types=1);

namespace Orion\Tests\Conformance;

use Orion\Storage\Storage;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\StorageConformanceTestCase;

final class InMemoryStorageConformanceTest extends StorageConformanceTestCase
{
    /** The in-memory store is the host's database, so the host writes to it directly. */
    private InMemoryStorage $host;

    protected function createStorage(): Storage
    {
        return $this->host = new InMemoryStorage();
    }

    protected function insertOnHostConnection(string $table, array $row): void
    {
        $this->host->insert($table, $row);
    }
}
