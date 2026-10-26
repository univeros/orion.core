<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\IndexName;
use Orion\Receipt\Receipt;
use Orion\Registry\IndexRegistration;

/**
 * A finished reindex: the alias, the version it left and the version it now points at, the writes replayed, the last
 * outbox offset replayed, and the receipt (null when receipts are off).
 */
final readonly class ReindexResult
{
    public function __construct(
        public IndexName $index,
        public int $fromVersion,
        public IndexRegistration $to,
        public int $documents,
        public int $toOffset,
        public ?Receipt $receipt,
    ) {
    }

    /**
     * @return array{index: string, from_version: int, to_version: int, physical: string, documents: int, to_offset: int, receipt: array<string, int|string|null>|null}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index->value,
            'from_version' => $this->fromVersion,
            'to_version' => $this->to->version,
            'physical' => $this->to->physical->value,
            'documents' => $this->documents,
            'to_offset' => $this->toOffset,
            'receipt' => $this->receipt?->toArray(),
        ];
    }
}
