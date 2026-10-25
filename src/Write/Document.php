<?php

declare(strict_types=1);

namespace Orion\Write;

use InvalidArgumentException;
use Orion\Support\Assert;

use function sprintf;

/**
 * What a projector produces for the engine. `version` is the outbox offset of the write that produced it, so a
 * replayed write and a live write of the same document converge on the newest (v1 §8.3).
 */
final readonly class Document
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(public string $id, public array $fields, public int $version)
    {
        Assert::notEmpty($id, 'A document id');
        if ($version < 0) {
            throw new InvalidArgumentException(sprintf('A document version is an outbox offset and cannot be negative, %d given.', $version));
        }
    }
}
