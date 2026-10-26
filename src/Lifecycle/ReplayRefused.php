<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\IndexName;
use Orion\Outbox\Delivered;
use RuntimeException;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function sprintf;

/**
 * The version being built refused documents, so the reindex stopped before the batch and will not swap the alias onto
 * an index missing them. Fix the documents (a newer outbox entry replaces a refused one) or the schema, then run the
 * reindex again to resume, or abort it.
 */
final class ReplayRefused extends RuntimeException
{
    private const int SHOWN = 3;

    public static function by(IndexName $physical, Delivered $delivered): self
    {
        $shown = array_map(
            static fn(array $failure): string => sprintf('%s at offset %d: %s', $failure['entry']->documentId, $failure['entry']->offset, $failure['error']),
            array_slice($delivered->failures, 0, self::SHOWN),
        );

        return new self(sprintf('%s refused %d document(s): %s.', $physical->value, count($delivered->failures), implode('; ', $shown)));
    }
}
