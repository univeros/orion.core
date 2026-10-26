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
        return $this->host = new InMemoryStorage(self::tables());
    }

    protected function insertOnHostConnection(string $table, array $row): void
    {
        $this->host->insert($table, $row);
    }

    protected function inHostTransaction(callable $work): void
    {
        $this->host->transaction($work);
    }
}
