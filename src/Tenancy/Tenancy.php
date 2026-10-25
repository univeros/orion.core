<?php

declare(strict_types=1);

namespace Orion\Tenancy;

/**
 * The explicit tenancy choice every boot makes: one tenant, or a resolver. There is no default, so a forgotten
 * tenant filter cannot be the silent outcome of a missing setting.
 */
final readonly class Tenancy
{
    private function __construct(public ?TenantResolver $resolver)
    {
    }

    public static function single(): self
    {
        return new self(null);
    }

    public static function resolvedBy(TenantResolver $resolver): self
    {
        return new self($resolver);
    }

    public function isSingle(): bool
    {
        return $this->resolver === null;
    }
}
