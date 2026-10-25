<?php

declare(strict_types=1);

namespace Orion\Adapter;

use DomainException;

use function sprintf;

/**
 * A clause the engine cannot honour, refused at build time instead of answered wrongly.
 */
final class UnsupportedClause extends DomainException
{
    private function __construct(public readonly Capability $capability, public readonly string $clause, public readonly string $engine)
    {
        parent::__construct(sprintf('%s cannot run the %s clause: it lacks the %s capability.', $engine, $clause, $capability->name));
    }

    public static function missing(Capability $capability, string $clause, string $engine): self
    {
        return new self($capability, $clause, $engine);
    }
}
