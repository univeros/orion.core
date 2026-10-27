<?php

declare(strict_types=1);

namespace Orion\Manifest;

use function array_filter;
use function array_values;

/**
 * The indices the application declares, by name.
 */
final readonly class Manifest
{
    /**
     * @param array<string, Declaration> $declarations by index name, in name order
     */
    public function __construct(private array $declarations = [])
    {
    }

    /**
     * @return list<Declaration>
     */
    public function declarations(): array
    {
        return array_values($this->declarations);
    }

    public function find(string $index): ?Declaration
    {
        return $this->declarations[$index] ?? null;
    }

    /**
     * The declarations that serve an endpoint, in index-name order.
     *
     * @return list<Declaration>
     */
    public function endpoints(): array
    {
        return array_values(array_filter($this->declarations, static fn(Declaration $declaration): bool => $declaration->endpoint !== null));
    }
}
