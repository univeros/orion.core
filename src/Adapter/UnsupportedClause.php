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
    private function __construct(public readonly Capability $capability, public readonly string $clause, public readonly string $engine, ?string $message = null)
    {
        parent::__construct($message ?? sprintf('%s cannot run the %s clause: it lacks the %s capability.', $engine, $clause, $capability->name));
    }

    public static function missing(Capability $capability, string $clause, string $engine): self
    {
        return new self($capability, $clause, $engine);
    }

    /**
     * A page past the offset window: paging deeper needs a cursor, which Orion's query does not carry yet.
     */
    public static function deepPaging(int $window, int $depth, string $engine): self
    {
        return new self(Capability::CursorPagination, 'offset pagination', $engine, sprintf('%s pages by offset to result %d at most; this page reaches result %d. Narrow the query, or wait for cursor pagination.', $engine, $window, $depth));
    }
}
