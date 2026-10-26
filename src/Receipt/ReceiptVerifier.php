<?php

declare(strict_types=1);

namespace Orion\Receipt;

/**
 * Checks receipt signatures with public keys only: what an auditor or a CI job holds, never the key that signs.
 */
interface ReceiptVerifier
{
    /** False for a bad signature and for a kid this verifier has no key for. */
    public function verify(string $message, string $signature, string $kid): bool;
}
