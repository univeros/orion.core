<?php

declare(strict_types=1);

namespace Orion\Receipt;

/**
 * What walking the receipt chain found: how many receipts, every break in chain order, and the head.
 */
final readonly class Verification
{
    /**
     * @param list<string> $breaks
     * @param Receipt|null $head the last receipt read: anchor its sequence and hash outside the database
     */
    public function __construct(public int $receipts, public array $breaks, public ?Receipt $head = null)
    {
    }

    public function ok(): bool
    {
        return $this->breaks === [];
    }

    /**
     * @return array{receipts: int, ok: bool, breaks: list<string>, head: array{sequence: int, hash: string}|null}
     */
    public function toArray(): array
    {
        return [
            'receipts' => $this->receipts,
            'ok' => $this->ok(),
            'breaks' => $this->breaks,
            'head' => $this->head === null ? null : ['sequence' => $this->head->sequence, 'hash' => $this->head->hash],
        ];
    }
}
