<?php

declare(strict_types=1);

namespace Orion\Adapter;

use Closure;

/**
 * A schema change an engine may finish asynchronously (Meilisearch settings tasks); synchronous engines return it
 * completed, so callers treat both alike. {@see await()} returns the completed copy.
 */
final readonly class PendingTask
{
    /**
     * @param (Closure(float): void)|null $await blocks until done or the timeout in seconds, throwing on failure
     */
    private function __construct(private ?Closure $await)
    {
    }

    public static function completed(): self
    {
        return new self(null);
    }

    /**
     * @param Closure(float): void $await
     */
    public static function pending(Closure $await): self
    {
        return new self($await);
    }

    public function isComplete(): bool
    {
        return $this->await === null;
    }

    public function await(float $timeout): self
    {
        if ($this->await === null) {
            return $this;
        }
        ($this->await)($timeout);

        return self::completed();
    }
}
