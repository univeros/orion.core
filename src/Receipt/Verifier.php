<?php

declare(strict_types=1);

namespace Orion\Receipt;

use Throwable;

use function base64_decode;
use function hash;
use function hash_equals;
use function sprintf;

/**
 * Walks the receipt chain (`orion verify`): each receipt's hash must be its message's, its sequence must follow the
 * one before, its `previous_hash` must be that receipt's hash, its signature must verify under its kid, and the chain
 * must reach the last sequence number issued, so a receipt edited, removed, reordered, forged without the key, or cut
 * off the end is reported. An unreadable row is reported too, never skipped.
 */
final readonly class Verifier
{
    public function __construct(private ReceiptStore $store, private ReceiptSigner $signer)
    {
    }

    public function verify(): Verification
    {
        $breaks = [];
        $count = 0;
        $previous = null;
        try {
            foreach ($this->store->walk() as $receipt) {
                ++$count;
                $breaks = [...$breaks, ...$this->check($receipt, $previous)];
                $previous = $receipt;
            }
        } catch (Throwable $unreadable) {
            $breaks[] = sprintf('the receipt after %d cannot be read: %s', $previous->sequence ?? 0, $unreadable->getMessage());
        }
        $issued = $this->store->head();
        if (($previous->sequence ?? 0) < $issued) {
            $breaks[] = sprintf('the chain ends at receipt %d but %d were issued', $previous->sequence ?? 0, $issued);
        }

        return new Verification($count, $breaks);
    }

    /**
     * @return list<string>
     */
    private function check(Receipt $receipt, ?Receipt $previous): array
    {
        $breaks = [];
        $expected = ($previous->sequence ?? 0) + 1;
        if ($receipt->sequence !== $expected) {
            $breaks[] = $receipt->sequence - $expected === 1
                ? sprintf('receipt %d follows receipt %d: receipt %d is missing', $receipt->sequence, $expected - 1, $expected)
                : sprintf('receipt %d follows receipt %d: receipts %d to %d are missing', $receipt->sequence, $expected - 1, $expected, $receipt->sequence - 1);
        }
        if ($receipt->previousHash !== $previous?->hash) {
            $breaks[] = sprintf('receipt %d does not follow the previous receipt\'s hash', $receipt->sequence);
        }
        $message = $receipt->message();
        if (!hash_equals($receipt->hash, hash('sha256', $message))) {
            // An altered receipt's signature fails as well; one break says it.
            return [sprintf('receipt %d was altered: its hash does not match its contents', $receipt->sequence), ...$breaks];
        }
        $signature = base64_decode($receipt->signature, true);
        if ($signature === false || !$this->signer->verify($message, $signature, $receipt->kid)) {
            $breaks[] = sprintf('receipt %d has a signature that does not verify under kid "%s"', $receipt->sequence, $receipt->kid);
        }

        return $breaks;
    }
}
