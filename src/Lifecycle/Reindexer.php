<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use DateTimeImmutable;
use LogicException;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Observability\Metrics;
use Orion\Outbox\Delivery;
use Orion\Outbox\Entry;
use Orion\Outbox\EntryKind;
use Orion\Outbox\Outbox;
use Orion\Receipt\Receipt;
use Orion\Receipt\ReceiptStore;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Registry\IndexRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\DuplicateKey;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Orion\Tenancy\ScopeInjector;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function array_filter;
use function count;
use function end;
use function sprintf;

use const DATE_ATOM;

/**
 * Zero-downtime reindex by replay (v1 §8.3), for one engine. The next version is registered and built from the
 * schema, the outbox is replayed into it from offset 0 until a read comes back short, the alias is swapped onto it,
 * and the outbox is replayed once more from where the replay stopped: an entry committed after the last read and
 * before the swap is caught up then, and any entry read after the swap is written by the drain to the new version,
 * because the drain resolves the alias after each read. Writes are versioned by offset, so the overlap converges.
 *
 * One reindex per alias at a time: `orion_reindex` holds the job and its lease, renewed every batch. A run that fails
 * gives its lease up so a retry resumes at once; a run that dies holds it until it expires, and the next run resumes
 * from the last offset replayed. Replaced versions stay until {@see prune()} drops them after a grace window.
 */
final readonly class Reindexer
{
    public const int DEFAULT_BATCH = 500;
    public const int DEFAULT_LEASE_SECONDS = 300;

    public function __construct(
        private string $engine,
        private Storage $storage,
        private EngineRegistry $engines,
        private IndexRegistry $registry,
        private AliasManager $aliases,
        private Outbox $outbox,
        private ScopeInjector $scopes,
        private Delivery $delivery,
        private ?ReceiptStore $receipts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Metrics $metrics,
        private int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
    ) {
        Assert::notEmpty($engine, 'The engine name');
        Assert::positive($leaseSeconds, 'A reindex lease');
    }

    /**
     * Rebuilds the alias's index from the outbox with the desired schema, or resumes the interrupted reindex to it.
     *
     * @throws LogicException when the alias is not built, belongs to another engine, or another reindex holds it
     * @throws ReplayRefused when the new version refuses documents: nothing is swapped
     */
    public function reindex(IndexSchema $desired, int $batch = self::DEFAULT_BATCH): ReindexResult
    {
        Assert::positive($batch, 'A reindex batch');
        $this->scopes->assertFits($desired);
        $this->current($desired->index);
        $job = $this->claim($desired, Uuid::v7()->toRfc4122());
        try {
            $target = $this->registry->find($job->physical)
                ?? throw new LogicException(sprintf('%s is not registered; abort the reindex of %s.', Assert::show($job->physical->value), Assert::show($job->alias->value)));
            $this->adapter()->ensure($desired->named($job->physical));
            $job = $this->replay($job, $batch);
            $this->storage->transaction(function () use ($job): void {
                $this->hold($job);
                // A run resumed after the swap committed keeps the swap's time, which the prune grace window counts from.
                if ($this->aliases->current($job->alias)?->physical->equals($job->physical) !== true) {
                    $this->aliases->swap($this->engine, $job->alias, $job->physical);
                }
            });
            $job = $this->replay($job, $batch);
            $receipt = $this->storage->transaction(function () use ($job, $target): ?Receipt {
                $this->finish($job);

                return $this->receipts?->append(Operation::Reindexed, $target, $job->fromVersion, $job->documents, 0, $job->position);
            });
        } catch (Throwable $failure) {
            $this->yieldLease($job, $failure);
            throw $failure;
        }

        return new ReindexResult($job->alias, $job->fromVersion, $target, $job->documents, $job->position, $receipt);
    }

    /**
     * The reindex in progress for the alias, or null. The job row is checked against the registry: it must build a
     * version of this alias no older than the one the alias points at, registered on the job's engine, so a corrupted
     * or forged row cannot steer an abort or a replay onto another index.
     *
     * @throws LogicException when the row disagrees with the registry
     */
    public function pending(IndexName $alias): ?ReindexJob
    {
        $row = $this->storage->findOne(Schema::REINDEX, ['alias' => $alias->value]);
        if ($row === null) {
            return null;
        }
        $job = ReindexJob::fromRow($row);
        $registration = $this->registry->find($job->physical);
        $current = $this->aliases->current($alias);
        if (
            ($registration !== null && ($registration->engine !== $job->engine || !$registration->alias->equals($alias)))
            || ($current !== null && (IndexNamer::versionOf($alias, $job->physical) ?? 0) < $current->version)
        ) {
            throw new LogicException(sprintf('The reindex of %s names %s, which the registry does not record as a version it may build; the row was altered.', Assert::show($alias->value), Assert::show($job->physical->value)));
        }

        return $job;
    }

    /**
     * Gives up an interrupted reindex: drops the version it was building and forgets it.
     *
     * @return IndexRegistration|null the version dropped, or null when no reindex was pending
     * @throws LogicException while a live run holds the lease, or once the alias already points at the new version
     */
    public function abort(IndexName $alias): ?IndexRegistration
    {
        $job = $this->pending($alias);
        if ($job === null) {
            return null;
        }
        if ($job->leaseUntil > $this->clock->now()) {
            throw new LogicException(sprintf('The reindex of %s is held until %s; abort it once its lease expires.', Assert::show($alias->value), $job->leaseUntil->format(DATE_ATOM)));
        }
        if ($this->aliases->current($alias)?->physical->equals($job->physical) === true) {
            throw new LogicException(sprintf('%s already points at %s; run the reindex again to finish it.', Assert::show($alias->value), Assert::show($job->physical->value)));
        }
        // Claim the job first: a run that took it over meanwhile wins, and nothing is dropped.
        $claim = $job->heldBy('abort-' . Uuid::v7()->toRfc4122(), $this->clock->now()->modify(sprintf('+%d seconds', $this->leaseSeconds)));
        if ($this->storage->update(Schema::REINDEX, ['alias' => $alias->value, 'holder' => $job->holder], $claim->toRow()) === 0) {
            throw new LogicException(sprintf('Another run took over the reindex of %s; it was not aborted.', Assert::show($alias->value)));
        }
        $registration = $this->registry->find($job->physical);
        $this->engines->get($job->engine)->drop($job->physical);
        $this->storage->transaction(function () use ($claim): void {
            $this->registry->forget($claim->physical);
            $this->finish($claim);
        });

        return $registration;
    }

    /**
     * The versions {@see prune()} would drop now: every version the alias no longer points at, once `$graceSeconds`
     * have passed since its last swap, except the version a pending reindex is building.
     *
     * @return list<IndexRegistration>
     */
    public function prunable(IndexName $alias, int $graceSeconds): array
    {
        $current = $this->current($alias);
        $swappedAt = $this->aliases->swappedAt($alias);
        if ($graceSeconds < 0 || $swappedAt === null || $swappedAt->modify(sprintf('+%d seconds', $graceSeconds)) > $this->clock->now()) {
            return [];
        }
        $building = $this->pending($alias)?->physical;
        $prunable = [];
        foreach ($this->registry->versions($alias) as $version) {
            if (!$version->physical->equals($current->physical) && ($building === null || !$version->physical->equals($building))) {
                $prunable[] = $version;
            }
        }

        return $prunable;
    }

    /**
     * Drops the {@see prunable()} versions from the engine and the registry, receipting each drop. Each version is
     * re-checked inside the transaction that drops it, after the receipt lock: the alias first, so a swap that has
     * committed is seen, then the job, so a version being built is seen. A version that is neither can never become
     * current again, because swaps only move to newly claimed versions.
     *
     * @return list<IndexRegistration> the versions dropped
     */
    public function prune(IndexName $alias, int $graceSeconds): array
    {
        $dropped = [];
        foreach ($this->prunable($alias, $graceSeconds) as $version) {
            try {
                $this->storage->transaction(function () use ($version): void {
                    $this->receipts?->appendAtHead(Operation::Dropped, $version, null, 0, fn(): int => $this->outbox->head($version->alias));
                    $current = $this->aliases->current($version->alias);
                    $building = $this->pending($version->alias)?->physical;
                    if ($current === null || $current->physical->equals($version->physical) || $building?->equals($version->physical) === true || !$this->registry->forget($version->physical)) {
                        throw new Skipped();
                    }
                    // Inside the transaction: if the commit fails, the version is still registered and the next prune
                    // drops it again, rather than leaving an index nothing knows about.
                    $this->adapter()->drop($version->physical);
                });
            } catch (Skipped) {
                continue;
            }
            $dropped[] = $version;
        }

        return $dropped;
    }

    /**
     * Takes the pending job when its lease has expired and it builds the same schema, or claims the next version.
     */
    private function claim(IndexSchema $desired, string $holder): ReindexJob
    {
        $alias = $desired->index;
        $now = $this->clock->now();
        $until = $now->modify(sprintf('+%d seconds', $this->leaseSeconds));
        $pending = $this->pending($alias);
        if ($pending !== null) {
            return $this->takeOver($pending, $desired, $holder, $until);
        }
        $current = $this->current($alias);
        $version = $this->registry->nextVersion($alias);
        $physical = IndexNamer::physical($alias, $version);
        if ($this->adapter()->inspect($physical) !== null) {
            throw new LogicException(sprintf('%s exists on the engine %s but is not registered; drop it before reindexing %s.', Assert::show($physical->value), Assert::show($this->engine), Assert::show($alias->value)));
        }
        $job = new ReindexJob($alias, $this->engine, $physical, $current->version, 0, 0, $holder, $until, $now);
        try {
            $this->storage->transaction(function () use ($job, $desired, $version, $now): void {
                $this->storage->insert(Schema::REINDEX, $job->toRow());
                $this->registry->register(new IndexRegistration($job->physical, $job->alias, $this->engine, $version, $desired->named($job->alias), $now));
            });
        } catch (DuplicateKey $raced) {
            throw new LogicException(sprintf('A reindex of %s is in progress.', Assert::show($alias->value)), 0, $raced);
        }

        return $job;
    }

    private function takeOver(ReindexJob $pending, IndexSchema $desired, string $holder, DateTimeImmutable $until): ReindexJob
    {
        $alias = $pending->alias;
        if ($pending->leaseUntil > $this->clock->now()) {
            throw new LogicException(sprintf('A reindex of %s to %s is in progress, held until %s.', Assert::show($alias->value), Assert::show($pending->physical->value), $pending->leaseUntil->format(DATE_ATOM)));
        }
        if ($pending->engine !== $this->engine || $this->registry->find($pending->physical)?->schema->hash() !== $desired->hash()) {
            throw new LogicException(sprintf('An interrupted reindex of %s to %s with another schema or engine is pending; abort it first (orion reindex %s --abort).', Assert::show($alias->value), Assert::show($pending->physical->value), $alias->value));
        }
        $taken = $pending->heldBy($holder, $until);
        if ($this->storage->update(Schema::REINDEX, ['alias' => $alias->value, 'holder' => $pending->holder], $taken->toRow()) === 0) {
            throw new LogicException(sprintf('Another run took over the reindex of %s.', Assert::show($alias->value)));
        }

        return $taken;
    }

    /**
     * Replays from the job's position until a read comes back short, renewing the lease every batch.
     */
    private function replay(ReindexJob $job, int $batch): ReindexJob
    {
        while (true) {
            $entries = $this->outbox->after($job->alias, $job->position, $batch);
            if ($entries === []) {
                return $job;
            }
            $delivered = $this->delivery->send($this->engine, $job->physical, $entries);
            if ($delivered->failures !== []) {
                throw ReplayRefused::by($job->physical, $delivered);
            }
            $sent = count(array_filter($entries, static fn(Entry $entry): bool => $entry->kind === EntryKind::Index));
            $last = end($entries);
            $job = $job->advanced($last->offset, $sent, $this->clock->now()->modify(sprintf('+%d seconds', $this->leaseSeconds)));
            $this->hold($job);
            $this->metrics->counter('orion.reindex.replayed', $sent, ['index' => $job->alias->value]);
            if (count($entries) < $batch) {
                return $job;
            }
        }
    }

    /**
     * Records the job's progress, and fails when another run has taken it over.
     */
    private function hold(ReindexJob $job): void
    {
        if ($this->storage->update(Schema::REINDEX, ['alias' => $job->alias->value, 'holder' => $job->holder], $job->toRow()) === 0) {
            throw new LogicException(sprintf('The reindex of %s was taken over by another run.', Assert::show($job->alias->value)));
        }
    }

    private function finish(ReindexJob $job): void
    {
        if ($this->storage->delete(Schema::REINDEX, ['alias' => $job->alias->value, 'holder' => $job->holder]) === 0) {
            throw new LogicException(sprintf('The reindex of %s was taken over by another run.', Assert::show($job->alias->value)));
        }
    }

    /**
     * Ends the lease of a run that failed, so a retry resumes at once; never hides the failure.
     */
    private function yieldLease(ReindexJob $job, Throwable $failure): void
    {
        try {
            $this->storage->update(Schema::REINDEX, ['alias' => $job->alias->value, 'holder' => $job->holder], ['lease_until' => $this->clock->now()]);
        } catch (Throwable $release) {
            $this->logger->warning('Orion could not release the reindex lease of {index}: {reason}; it expires at {until}.', [
                'index' => $job->alias->value,
                'reason' => $release->getMessage(),
                'until' => $job->leaseUntil->format(DATE_ATOM),
                'exception' => $release,
                'cause' => $failure,
            ]);
        }
    }

    /**
     * @throws LogicException when the alias is not built, or another engine built it
     */
    private function current(IndexName $alias): IndexRegistration
    {
        $current = $this->aliases->current($alias)
            ?? throw new LogicException(sprintf('%s has not been built; apply its schema first.', Assert::show($alias->value)));
        if ($current->engine !== $this->engine) {
            throw new LogicException(sprintf('%s was built by the engine %s, not %s.', Assert::show($alias->value), Assert::show($current->engine), Assert::show($this->engine)));
        }

        return $current;
    }

    private function adapter(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return $this->engines->get($this->engine);
    }
}
