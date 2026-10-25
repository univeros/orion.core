<?php

declare(strict_types=1);

namespace Orion\Tenancy;

/**
 * Resolves the current request's tenant, such as the active-org claim of a Polaris token. The host binds it to
 * its request context; it is asked per query, never cached in config.
 */
interface TenantResolver
{
    public function resolve(): TenantScope;
}
