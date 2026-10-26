<?php

declare(strict_types=1);

namespace Orion\Tests\Conformance;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\TenancyIsolationTestCase;

final class InMemoryTenancyIsolationTest extends TenancyIsolationTestCase
{
    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new InMemoryEngine();
    }

    protected function nativeMatchAll(): callable
    {
        return static fn(array $source): bool => true;
    }

    protected function nativeShapes(): array
    {
        return ['the foreign tenant by name' => static fn(array $source): bool => ($source['org_id'] ?? null) === 'globex'];
    }
}
