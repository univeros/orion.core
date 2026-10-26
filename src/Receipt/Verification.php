<?php

declare(strict_types=1);

namespace Orion\Receipt;

/**
 * What walking the receipt chain found: how many receipts, and every break, in chain order.
 */
final readonly class Verification
{
    /**
     * @param list<string> $breaks
     */
    public function __construct(public int $receipts, public array $breaks)
    {
    }

    public function ok(): bool
    {
        return $this->breaks === [];
    }

    /**
     * @return array{receipts: int, ok: bool, breaks: list<string>}
     */
    public function toArray(): array
    {
        return ['receipts' => $this->receipts, 'ok' => $this->ok(), 'breaks' => $this->breaks];
    }
}
