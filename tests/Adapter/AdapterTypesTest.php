<?php

declare(strict_types=1);

namespace Orion\Tests\Adapter;

use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
use Orion\Adapter\UnsupportedClause;
use Orion\IndexName;
use PHPUnit\Framework\TestCase;

use function array_map;

final class AdapterTypesTest extends TestCase
{
    public function testTheCapabilitiesAreExactlyTheSpecifiedOnes(): void
    {
        self::assertSame(
            ['Vectors', 'HybridFusion', 'NestedDocuments', 'Facets', 'Highlighting', 'NativeAliases', 'AsyncWrites', 'CursorPagination', 'FunctionScore', 'Streaming', 'OptimisticConcurrency'],
            array_map(static fn(Capability $capability): string => $capability->name, Capability::cases()),
        );
    }

    public function testAnUnsupportedClauseNamesTheEngineTheClauseAndTheMissingCapability(): void
    {
        $exception = UnsupportedClause::missing(Capability::Vectors, 'vector', 'meilisearch');

        self::assertSame(Capability::Vectors, $exception->capability);
        self::assertSame('vector', $exception->clause);
        self::assertSame('meilisearch', $exception->engine);
        self::assertStringContainsString('meilisearch', $exception->getMessage());
        self::assertStringContainsString('Vectors', $exception->getMessage());
    }

    public function testAPendingTaskCompletesOnAwait(): void
    {
        $awaited = null;
        $task = PendingTask::pending(static function (float $timeout) use (&$awaited): void {
            $awaited = $timeout;
        });

        self::assertFalse($task->isComplete());
        self::assertTrue($task->await(2.5)->isComplete());
        self::assertSame(2.5, $awaited);
        self::assertTrue(PendingTask::completed()->isComplete());
    }

    public function testIndexNamesAreTheCommonDenominatorOfTheEngines(): void
    {
        self::assertSame('products_v3', IndexName::of('products_v3')->value);
        self::assertTrue(IndexName::of('products')->equals(IndexName::of('products')));

        foreach (['', 'Products', 'has space', '_leading', '-leading', 'dot.ted', "products\n"] as $invalid) {
            try {
                IndexName::of($invalid);
                self::fail("'$invalid' was accepted as an index name.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
