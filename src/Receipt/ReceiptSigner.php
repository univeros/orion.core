<?php

declare(strict_types=1);

namespace Orion\Receipt;

/**
 * Signs receipts (docs/spec/revision.md §3). `kid` names the key a signature was made with, so keys can rotate:
 * verification checks a signature under the key its receipt names, the current one or a previous one.
 */
interface ReceiptSigner extends ReceiptVerifier
{
    /** The id of the key {@see sign()} uses, at most 64 characters of `[A-Za-z0-9._-]`. */
    public function kid(): string;

    /** A detached signature over the message, as raw bytes. */
    public function sign(string $message): string;
}
