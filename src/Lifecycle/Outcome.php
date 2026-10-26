<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use Orion\Receipt\Receipt;
use Orion\Registry\IndexRegistration;
use Orion\Schema\MigrationPlan;

/**
 * The result of one apply: the operation, the registration now current for the alias, the plan that was applied (or
 * could not be), and the receipt issued for it when the operation changed something and receipts are on. A pure
 * function of the inputs, so two runs from the same state compare equal, receipt included.
 */
final readonly class Outcome
{
    public function __construct(public Operation $operation, public IndexRegistration $registration, public MigrationPlan $plan, public ?Receipt $receipt = null)
    {
    }

    /**
     * @return array{operation: string, index: string, physical: string, version: int, schema_hash: string, plan: array<string, mixed>, receipt: array<string, int|string|null>|null}
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
            'receipt' => $this->receipt?->toArray(),
        ];
    }
}
