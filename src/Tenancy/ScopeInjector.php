<?php

declare(strict_types=1);

namespace Orion\Tenancy;

use InvalidArgumentException;
use LogicException;
use Orion\IndexName;
use Orion\Lifecycle\AliasManager;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;

use function sprintf;

/**
 * Turns the tenancy choice into each operation's {@see IndexScope} (v1 §9, the shared-index layout): under a
 * resolver every index carries an org key and the tenant is resolved anew for every operation, never cached; under
 * single tenancy no index carries one. An index that does not fit is refused, so a forgotten org key cannot leave a
 * shared index unfiltered and a forgotten resolver cannot leave one tenant reading another's.
 */
final readonly class ScopeInjector
{
    public function __construct(private Tenancy $tenancy, private AliasManager $aliases)
    {
    }

    /**
     * @throws LogicException when the schema does not fit the tenancy
     */
    public function assertFits(IndexSchema $schema): void
    {
        if ($this->tenancy->isSingle() && $schema->orgKey !== null) {
            throw new LogicException(sprintf('%s declares the org key %s, but Orion runs a single tenant: configure a tenant resolver, or drop the org key.', Assert::show($schema->index->value), Assert::show($schema->orgKey)));
        }
        if (!$this->tenancy->isSingle() && $schema->orgKey === null) {
            throw new LogicException(sprintf('%s has no org key, but Orion resolves tenants: every index it serves must declare one (org_key), or no filter could keep tenants apart.', Assert::show($schema->index->value)));
        }
    }

    /**
     * The scope of one operation on an index with this schema, the tenant resolved now.
     */
    public function scope(IndexSchema $schema): IndexScope
    {
        $this->assertFits($schema);
        if ($this->tenancy->resolver === null || $schema->orgKey === null) {
            return IndexScope::unscoped();
        }

        return IndexScope::of($this->tenancy->resolver->resolve(), $schema->orgKey);
    }

    /**
     * {@see scope()} for the index the alias serves now. Under single tenancy an alias not built yet is unscoped;
     * under a resolver its org key is unknown until it is built, so it is refused.
     *
     * @throws InvalidArgumentException under a resolver, when the alias is not built
     */
    public function forIndex(IndexName $alias): IndexScope
    {
        $current = $this->aliases->current($alias);
        if ($current !== null) {
            return $this->scope($current->schema);
        }
        if ($this->tenancy->isSingle()) {
            return IndexScope::unscoped();
        }

        throw new InvalidArgumentException(sprintf('%s is not built, so its org key is unknown: apply its schema before writing to it.', Assert::show($alias->value)));
    }
}
