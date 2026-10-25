<?php

declare(strict_types=1);

namespace Orion\Write;

use Orion\Support\Assert;

/**
 * What happened to one document of a bulk write.
 */
final readonly class WriteOutcome
{
    private function __construct(public string $id, public WriteStatus $status, public ?string $error)
    {
    }

    public static function applied(string $id): self
    {
        return new self($id, WriteStatus::Applied, null);
    }

    public static function stale(string $id): self
    {
        return new self($id, WriteStatus::Stale, null);
    }

    public static function failed(string $id, string $error): self
    {
        return new self($id, WriteStatus::Failed, Assert::notEmpty($error, 'A failed write\'s error'));
    }
}
