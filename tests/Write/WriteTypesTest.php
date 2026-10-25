<?php

declare(strict_types=1);

namespace Orion\Tests\Write;

use InvalidArgumentException;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\WriteOutcome;
use Orion\Write\WriteStatus;
use PHPUnit\Framework\TestCase;

final class WriteTypesTest extends TestCase
{
    public function testADocumentCarriesItsOutboxOffsetAsVersion(): void
    {
        $document = new Document('42', ['title' => 'Widget'], 7);

        self::assertSame('42', $document->id);
        self::assertSame(['title' => 'Widget'], $document->fields);
        self::assertSame(7, $document->version);
    }

    public function testADocumentNeedsAnId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Document('', [], 1);
    }

    public function testAVersionCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Document('42', [], -1);
    }

    public function testACompletedResultSeparatesFailures(): void
    {
        $result = BulkResult::completed(
            WriteOutcome::applied('a'),
            WriteOutcome::stale('b'),
            WriteOutcome::failed('c', 'mapper_parsing_exception'),
        );

        self::assertTrue($result->isComplete());
        self::assertSame($result, $result->await(30.0));
        self::assertEquals([WriteOutcome::failed('c', 'mapper_parsing_exception')], $result->failures());
        self::assertSame(WriteStatus::Stale, $result->outcomes[1]->status);
    }

    public function testAStaleWriteHasConvergedAndIsNotAFailure(): void
    {
        self::assertSame([], BulkResult::completed(WriteOutcome::stale('a'))->failures());
    }

    public function testAPendingResultCompletesOnAwait(): void
    {
        $polledWith = null;
        $pending = BulkResult::pending(static function (float $timeout) use (&$polledWith): array {
            $polledWith = $timeout;

            return [WriteOutcome::applied('a')];
        });

        self::assertFalse($pending->isComplete());
        self::assertSame([], $pending->outcomes);

        $done = $pending->await(5.0);

        self::assertSame(5.0, $polledWith);
        self::assertTrue($done->isComplete());
        self::assertEquals([WriteOutcome::applied('a')], $done->outcomes);
        self::assertFalse($pending->isComplete());
    }

    public function testAFailedOutcomeNeedsAReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteOutcome::failed('a', '');
    }
}
