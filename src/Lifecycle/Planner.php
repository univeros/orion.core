<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use LogicException;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Registry\IndexRegistry;
use Orion\Schema\IndexSchema;
use Orion\Schema\MigrationPlan;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Psr\Clock\ClockInterface;
use Throwable;

use function sprintf;

/**
 * The schema lifecycle for one engine (v1 §8.1, §8.2): a pure plan from the desired schema to what is live and
 * registered, and an apply that creates version 1 behind the alias, applies additive plans in place, and never
 * touches a live index for a breaking one. An additive plan the engine refuses (`LogicException`: Elasticsearch
 * cannot add analysis to a live index) is reported as {@see Operation::ReindexRequired}, like a breaking one.
 */
final readonly class Planner
{
    /** How long an in-place apply may take to complete on an asynchronous engine. */
    public const float DEFAULT_APPLY_TIMEOUT = 30.0;

    public function __construct(
        private string $engine,
        private EngineRegistry $engines,
        private IndexRegistry $registry,
        private AliasManager $aliases,
        private Storage $storage,
        private ClockInterface $clock,
        private float $applyTimeout = self::DEFAULT_APPLY_TIMEOUT,
    ) {
        Assert::notEmpty($engine, 'The engine name');
    }

    /**
     * Fields are diffed against the live physical index, the org key and native settings against the registration;
     * an alias Orion never built plans a create.
     */
    public function plan(IndexSchema $desired): MigrationPlan
    {
        $current = $this->current($desired->index);
        if ($current === null) {
            return MigrationPlan::between($desired, null);
        }

        return MigrationPlan::between($desired, $this->adapter()->inspect($current->physical), $current->schema);
    }

    public function apply(IndexSchema $desired): Outcome
    {
        $current = $this->current($desired->index);
        $plan = $this->plan($desired);
        if ($current === null) {
            return $this->create($desired, $plan);
        }
        if ($plan->isEmpty()) {
            return new Outcome(Operation::Unchanged, $current, $plan);
        }
        if ($plan->requiresReindex()) {
            return new Outcome(Operation::ReindexRequired, $current, $plan);
        }
        try {
            // The plan names the alias; the engine applies it to the physical index behind it.
            $this->adapter()->apply($plan->named($current->physical))->await($this->applyTimeout);
        } catch (LogicException) {
            return new Outcome(Operation::ReindexRequired, $current, $plan);
        }
        $applied = new IndexRegistration($current->physical, $current->alias, $current->engine, $current->version, $desired, $current->createdAt);
        $this->registry->record($applied);

        return new Outcome(Operation::Applied, $applied, $plan);
    }

    private function create(IndexSchema $desired, MigrationPlan $plan): Outcome
    {
        $alias = $desired->index;
        $physical = IndexNamer::physical($alias, $this->registry->nextVersion($alias));
        $adapter = $this->adapter();
        // Fail closed on anything Orion did not build: adopting it would register a schema the live index may not have.
        foreach ([$alias, $physical] as $taken) {
            if ($adapter->inspect($taken) !== null) {
                throw new LogicException(sprintf('%s exists on the engine %s but is not registered; drop it or register it before Orion builds %s.', Assert::show($taken->value), Assert::show($this->engine), Assert::show($alias->value)));
            }
        }
        $adapter->ensure($desired->named($physical));
        $registration = new IndexRegistration($physical, $alias, $this->engine, 1, $desired, $this->clock->now());
        try {
            $this->storage->transaction(function () use ($registration, $alias, $physical): void {
                $this->registry->record($registration);
                $this->aliases->swap($this->engine, $alias, $physical);
            });
        } catch (Throwable $e) {
            $adapter->drop($physical);
            throw $e;
        }

        return new Outcome(Operation::Created, $registration, $plan);
    }

    /**
     * The registration the alias currently points at, or null when Orion never built it.
     *
     * @throws LogicException when the alias points at an unregistered index, or one another engine built
     */
    private function current(IndexName $alias): ?IndexRegistration
    {
        $physical = $this->aliases->current($alias);
        if ($physical === null) {
            return null;
        }
        $registration = $this->registry->find($physical)
            ?? throw new LogicException(sprintf('The alias %s points at %s, which is not registered.', Assert::show($alias->value), Assert::show($physical->value)));
        if ($registration->engine !== $this->engine) {
            throw new LogicException(sprintf('%s was built by the engine %s, not %s.', Assert::show($alias->value), Assert::show($registration->engine), Assert::show($this->engine)));
        }

        return $registration;
    }

    private function adapter(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return $this->engines->get($this->engine);
    }
}
