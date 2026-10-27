<?php

declare(strict_types=1);

namespace Orion\Tests\Conformance;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\Equivalence\QueryEquivalenceTestCase;

/**
 * The oracle against itself: proves the generator stays inside what the searcher accepts.
 */
final class InMemoryQueryEquivalenceTest extends QueryEquivalenceTestCase
{
    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new InMemoryEngine();
    }
}
