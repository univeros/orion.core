<?php

declare(strict_types=1);

namespace Orion\Write;

use Closure;

use function array_filter;
use function array_values;

/**
 * The outcome of a bulk write, awaitable so synchronous engines and asynchronous ones (Meilisearch) look the same
 * to the caller (v1 §10). {@see await()} returns the completed copy; a pending result has no outcomes yet.
 */
final readonly class BulkResult
{
    /**
     * @param list<WriteOutcome> $outcomes
     * @param (Closure(float): list<WriteOutcome>)|null $await
     */
    private function __construct(public array $outcomes, private ?Closure $await)
    {
    }

    public static function completed(WriteOutcome ...$outcomes): self
    {
        return new self(array_values($outcomes), null);
    }

    /**
     * @param Closure(float): list<WriteOutcome> $await blocks until the engine finishes or the timeout in seconds
     */
    public static function pending(Closure $await): self
    {
        return new self([], $await);
    }

    public function isComplete(): bool
    {
        return $this->await === null;
    }

    public function await(float $timeout): self
    {
        return $this->await === null ? $this : self::completed(...($this->await)($timeout));
    }

    /**
     * @return list<WriteOutcome>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->outcomes, static fn(WriteOutcome $outcome): bool => $outcome->status === WriteStatus::Failed));
    }
}
