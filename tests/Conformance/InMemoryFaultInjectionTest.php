<?php

declare(strict_types=1);

namespace Orion\Tests\Conformance;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\FaultInjectionTestCase;

final class InMemoryFaultInjectionTest extends FaultInjectionTestCase
{
    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new InMemoryEngine();
    }
}
