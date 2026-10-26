<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\RequiresReindex;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Outbox\Outbox;
use Orion\Receipt\Receipt;
use Orion\Receipt\ReceiptStore;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Registry\IndexRegistry;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function sprintf;

/**
 * The schema lifecycle for one engine (v1 §8.1, §8.2): a pure plan from the desired schema to what is live and
 * registered, and an apply that creates version 1 behind the alias, applies additive plans in place, and never
 * touches a live index for a breaking one. A create and an apply each issue a receipt (v1 §11) in the transaction
 * that records them, so neither the alias nor the registration moves without one. An additive plan the engine refuses with {@see RequiresReindex}
 * (Elasticsearch cannot add analysis to a live index) is reported as {@see Operation::ReindexRequired}, like a
 * breaking one.
 */
final readonly class Planner
{
    /** How long an in-place apply may take to complete on an asynchronous engine. */
    public const float DEFAULT_APPLY_TIMEOUT = 30.0;
    private const int FIRST_VERSION = 1;

    /**
     * @param ReceiptStore|null $receipts null when receipts are off (no signer configured)
     */
    public function __construct(
        private string $engine,
        private Storage $storage,
        private EngineRegistry $engines,
        private IndexRegistry $registry,
        private AliasManager $aliases,
        private Outbox $outbox,
        private ?ReceiptStore $receipts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private float $applyTimeout = self::DEFAULT_APPLY_TIMEOUT,
    ) {
        Assert::notEmpty($engine, 'The engine name');
    }

    /**
     * Fields are diffed against the live physical index, the org key and native settings against the registration;
     * an alias Orion never built plans a create.
     *
     * @throws LogicException when the registered physical index is missing from the engine, or another engine built it
     */
    public function plan(IndexSchema $desired): MigrationPlan
    {
        $current = $this->current($desired->index);
        if ($current === null) {
            return MigrationPlan::between($desired, null);
        }

        return MigrationPlan::between($desired, $this->live($current), $current->schema);
    }

    public function apply(IndexSchema $desired): Outcome
    {
        $current = $this->current($desired->index);
        if ($current === null) {
            return $this->create($desired);
        }
        if ($this->storage->findOne(Schema::REINDEX, ['alias' => $current->alias->value]) !== null) {
            throw new LogicException(sprintf('A reindex of %s is pending; apply once it completes, or abort it.', Assert::show($current->alias->value)));
        }
        $plan = MigrationPlan::between($desired, $this->live($current), $current->schema);
        if ($plan->requiresReindex()) {
            return new Outcome(Operation::ReindexRequired, $current, $plan);
        }
        // Query-time attributes (a boost) change the declaration without changing the index: queries compile from
        // the registration, so it is refreshed whenever the declaration differs, with or without an engine change.
        $redeclared = $current->schema->hash() !== $desired->hash();
        if ($plan->isEmpty() && !$redeclared) {
            return new Outcome(Operation::Unchanged, $current, $plan);
        }
        if (!$plan->isEmpty()) {
            try {
                // The plan names the alias; the engine applies it to the physical index behind it.
                $this->adapter()->apply($plan->named($current->physical))->await($this->applyTimeout);
            } catch (RequiresReindex) {
                return new Outcome(Operation::ReindexRequired, $current, $plan);
            }
        }
        $next = $redeclared ? new IndexRegistration($current->physical, $current->alias, $current->engine, $current->version, $desired, $current->createdAt) : $current;
        $receipt = $this->storage->transaction(function () use ($redeclared, $next, $current): ?Receipt {
            if ($redeclared) {
                $this->registry->update($next);
            }

            return $this->receipt(Operation::Applied, $next, $current->version, 0);
        });

        return new Outcome(Operation::Applied, $next, $plan, $receipt);
    }

    /**
     * Version 1 behind the alias. The registry row is claimed first (the database arbitrates concurrent creates), then
     * the index is built and the alias swapped; on any failure the index this call owns is dropped and the claim
     * released. Anything on the engine Orion did not build is refused: adopting it would register a schema the live
     * index may not have.
     */
    private function create(IndexSchema $desired): Outcome
    {
        $alias = $desired->index;
        if (IndexNamer::isPhysical($alias)) {
            throw new InvalidArgumentException(sprintf('%s cannot be an alias: names ending in _v<n> are the physical indices behind one.', Assert::show($alias->value)));
        }
        $physical = IndexNamer::physical($alias, self::FIRST_VERSION);
        $versions = $this->registry->versions($alias);
        if ($versions !== []) {
            throw new LogicException(sprintf('%s is registered (%s) but no alias points at it; recover it before building it again.', Assert::show($alias->value), Assert::show($versions[0]->physical->value)));
        }
        $adapter = $this->adapter();
        foreach ([$alias, $physical] as $taken) {
            if ($adapter->inspect($taken) !== null) {
                throw new LogicException(sprintf('%s exists on the engine %s but is not registered; drop it before Orion builds %s.', Assert::show($taken->value), Assert::show($this->engine), Assert::show($alias->value)));
            }
        }
        $registration = new IndexRegistration($physical, $alias, $this->engine, self::FIRST_VERSION, $desired, $this->clock->now());
        $this->registry->register($registration);
        try {
            $adapter->ensure($desired->named($physical));
            $receipt = $this->storage->transaction(function () use ($alias, $physical, $registration): ?Receipt {
                $this->aliases->swap($this->engine, $alias, $physical);

                return $this->receipt(Operation::Created, $registration, null, 0);
            });
        } catch (Throwable $failure) {
            $this->release($registration, $failure);
            throw $failure;
        }

        return new Outcome(Operation::Created, $registration, MigrationPlan::between($desired, null), $receipt);
    }

    /**
     * The receipt for an operation that changed the index or its registration, at the outbox head it saw; null when
     * receipts are off. Call it inside the transaction that records the change, so neither commits without the other.
     */
    private function receipt(Operation $operation, IndexRegistration $to, ?int $fromVersion, int $documents): ?Receipt
    {
        $head = $this->outbox->head($to->alias);

        return $this->receipts?->append($operation, $to, $fromVersion, $documents, $head, $head);
    }

    /**
     * Drops the index this call built and releases its claim; a failing drop is logged, never allowed to hide the
     * failure that caused it.
     */
    private function release(IndexRegistration $registration, Throwable $failure): void
    {
        try {
            $this->adapter()->drop($registration->physical);
        } catch (Throwable $drop) {
            $this->logger->error('Orion could not drop {physical} after failing to build {alias}: {reason}; drop it by hand.', [
                'physical' => $registration->physical->value,
                'alias' => $registration->alias->value,
                'reason' => $drop->getMessage(),
                'exception' => $drop,
                'cause' => $failure,
            ]);
        }
        $this->registry->forget($registration->physical);
    }

    /**
     * @throws LogicException when another engine built the alias
     */
    private function current(IndexName $alias): ?IndexRegistration
    {
        $registration = $this->aliases->current($alias);
        if ($registration !== null && $registration->engine !== $this->engine) {
            throw new LogicException(sprintf('%s was built by the engine %s, not %s.', Assert::show($alias->value), Assert::show($registration->engine), Assert::show($this->engine)));
        }

        return $registration;
    }

    /**
     * @throws LogicException when the registered physical index is missing from the engine: planning against nothing
     *                        would make every field additive
     */
    private function live(IndexRegistration $current): LiveSchema
    {
        return $this->adapter()->inspect($current->physical)
            ?? throw new LogicException(sprintf('%s is registered for %s but missing from the engine %s; recover it before planning.', Assert::show($current->physical->value), Assert::show($current->alias->value), Assert::show($this->engine)));
    }

    private function adapter(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return $this->engines->get($this->engine);
    }
}
