<?php

declare(strict_types=1);

namespace Orion\Write;

use InvalidArgumentException;
use Orion\Support\Assert;

use function sprintf;

/**
 * A deletion, version-stamped like a write: `version` is the outbox offset of the delete, so a stale replayed write
 * of the same document cannot resurrect it, and a stale replayed delete cannot remove a newer write (v1 §8.3).
 */
final readonly class Tombstone
{
    public function __construct(public string $id, public int $version)
    {
        Assert::notEmpty($id, 'A document id');
        if ($version < 0) {
            throw new InvalidArgumentException(sprintf('A tombstone version is an outbox offset and cannot be negative, %d given.', $version));
        }
    }
}
