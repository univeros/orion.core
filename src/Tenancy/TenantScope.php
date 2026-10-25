<?php

declare(strict_types=1);

namespace Orion\Tenancy;

use Orion\Support\Assert;

/**
 * The tenant a request is scoped to (v1 §9). Injected beneath the AST into every compiled query from WP6.
 */
final readonly class TenantScope
{
    public function __construct(public string $tenant)
    {
        Assert::notEmpty($tenant, 'A tenant');
    }
}
