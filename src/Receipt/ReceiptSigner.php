<?php

declare(strict_types=1);

namespace Orion\Receipt;

/**
 * Signs receipts (docs/spec/revision.md §3). `kid` names the key a signature was made with, so keys can rotate:
 * {@see verify()} checks a signature under the key its receipt names, the current one or a previous one.
 */
interface ReceiptSigner
{
    /** The id of the key {@see sign()} uses, at most 64 characters of `[A-Za-z0-9._-]`. */
    public function kid(): string;

    /** A detached signature over the message, as raw bytes. */
    public function sign(string $message): string;

    /** False for a bad signature and for a kid this signer has no key for. */
    public function verify(string $message, string $signature, string $kid): bool;
}
