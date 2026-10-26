<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\Registry\IndexRegistration;
use Orion\Schema\MigrationPlan;

/**
 * The result of one apply: the operation, the registration now current for the alias, and the plan that was
 * applied (or could not be). A pure function of the inputs, so two runs from the same state compare equal; it is
 * the seam the signed receipt (WP4) is computed over.
 */
final readonly class Outcome
{
    public function __construct(public Operation $operation, public IndexRegistration $registration, public MigrationPlan $plan)
    {
    }

    /**
     * @return array{operation: string, index: string, physical: string, version: int, schema_hash: string, plan: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'index' => $this->registration->alias->value,
            'physical' => $this->registration->physical->value,
            'version' => $this->registration->version,
            'schema_hash' => $this->registration->schema->hash(),
            'plan' => $this->plan->toArray(),
        ];
    }
}
