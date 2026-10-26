<?php

declare(strict_types=1);

namespace Orion\Wiring;

use LogicException;
use Orion\Lifecycle\AliasManager;
use Orion\Lifecycle\Planner;
use Orion\Lifecycle\Reindexer;
use Orion\Observability\Metrics;
use Orion\Observability\NullMetrics;
use Orion\Outbox\DeadLetters;
use Orion\Outbox\Delivery;
use Orion\Outbox\Dispatcher;
use Orion\Outbox\Drain;
use Orion\Outbox\Outbox;
use Orion\Outbox\SyncDispatcher;
use Orion\Receipt\ReceiptStore;
use Orion\Receipt\Verifier;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistry;
use Orion\Storage\Storage;
use Orion\Support\InMemoryCache;
use Orion\Support\NullEventDispatcher;
use Orion\Support\SystemClock;
use Orion\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * The Orion object graph, built explicitly from a {@see Config} with no container: every service once, on first
 * use, a configured port winning over its core default.
 */
final class Graph
{
    /** @var array<class-string, object> */
    private array $instances = [];
    /** @var array<string, Planner> */
    private array $planners = [];
    /** @var array<string, Reindexer> */
    private array $reindexers = [];

    public function __construct(private readonly Config $config)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function storage(): Storage
    {
        return $this->config->storage;
    }

    public function engines(): EngineRegistry
    {
        return $this->config->engines;
    }

    public function tenancy(): Tenancy
    {
        return $this->config->tenancy;
    }

    public function registry(): IndexRegistry
    {
        return $this->once(IndexRegistry::class, fn(): IndexRegistry => new IndexRegistry($this->storage()));
    }

    /**
     * Record into it inside the transaction that writes the entity, on the configured storage.
     */
    public function outbox(): Outbox
    {
        return $this->once(Outbox::class, fn(): Outbox => new Outbox($this->storage(), $this->clock()));
    }

    /**
     * Writes an index's outbox entries to its engine; run it after commits, through {@see dispatcher()} or the CLI.
     */
    public function drain(): Drain
    {
        return $this->once(Drain::class, fn(): Drain => new Drain($this->storage(), $this->outbox(), $this->aliases(), $this->delivery(), $this->deadLetters(), $this->metrics()));
    }

    public function deadLetters(): DeadLetters
    {
        return $this->once(DeadLetters::class, fn(): DeadLetters => new DeadLetters($this->storage(), $this->outbox(), $this->aliases(), $this->delivery(), $this->clock()));
    }

    public function dispatcher(): Dispatcher
    {
        return $this->config->dispatcher ?? $this->once(SyncDispatcher::class, fn(): SyncDispatcher => new SyncDispatcher($this->drain()));
    }

    /**
     * The receipt chain, for reading; appending needs the configured signer.
     */
    public function receipts(): ReceiptStore
    {
        return $this->once(ReceiptStore::class, fn(): ReceiptStore => new ReceiptStore($this->storage(), $this->config->receiptSigner, $this->clock()));
    }

    /**
     * @throws LogicException without a configured signer: signatures cannot be checked without its keys
     */
    public function verifier(): Verifier
    {
        $signer = $this->config->receiptSigner ?? throw new LogicException('Verifying receipts needs the ReceiptSigner that holds their keys; pass one in the Config.');

        return $this->once(Verifier::class, fn(): Verifier => new Verifier($this->receipts(), $signer));
    }

    public function metrics(): Metrics
    {
        return $this->config->metrics ?? $this->once(NullMetrics::class, static fn(): NullMetrics => new NullMetrics());
    }

    public function aliases(): AliasManager
    {
        return $this->once(AliasManager::class, fn(): AliasManager => new AliasManager($this->storage(), $this->engines(), $this->registry(), $this->clock()));
    }

    /**
     * The schema lifecycle for one registered engine.
     */
    public function planner(string $engine): Planner
    {
        $this->engines()->get($engine);

        return $this->planners[$engine] ??= new Planner($engine, $this->storage(), $this->engines(), $this->registry(), $this->aliases(), $this->outbox(), $this->issuer(), $this->clock(), $this->logger());
    }

    /**
     * The store lifecycle operations append to, or null when receipts are off.
     */
    private function issuer(): ?ReceiptStore
    {
        return $this->config->receiptSigner === null ? null : $this->receipts();
    }

    private function delivery(): Delivery
    {
        return $this->once(Delivery::class, fn(): Delivery => new Delivery($this->engines()));
    }

    /**
     * Zero-downtime reindex by replay, for one registered engine.
     */
    public function reindexer(string $engine): Reindexer
    {
        $this->engines()->get($engine);

        return $this->reindexers[$engine] ??= new Reindexer($engine, $this->storage(), $this->engines(), $this->registry(), $this->aliases(), $this->outbox(), $this->delivery(), $this->issuer(), $this->clock(), $this->logger(), $this->metrics());
    }

    public function clock(): ClockInterface
    {
        return $this->config->clock ?? $this->once(SystemClock::class, static fn(): SystemClock => new SystemClock());
    }

    public function logger(): LoggerInterface
    {
        return $this->config->logger ?? $this->once(NullLogger::class, static fn(): NullLogger => new NullLogger());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->config->events ?? $this->once(NullEventDispatcher::class, static fn(): NullEventDispatcher => new NullEventDispatcher());
    }

    public function cache(): CacheInterface
    {
        return $this->config->cache ?? $this->once(InMemoryCache::class, static fn(): InMemoryCache => new InMemoryCache());
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(): T $build
     * @return T
     */
    private function once(string $class, callable $build): object
    {
        /** @var T */
        return $this->instances[$class] ??= $build();
    }
}
