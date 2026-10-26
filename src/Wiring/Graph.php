<?php

declare(strict_types=1);

namespace Orion\Wiring;

use Orion\Lifecycle\AliasManager;
use Orion\Lifecycle\Planner;
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

    public function aliases(): AliasManager
    {
        return $this->once(AliasManager::class, fn(): AliasManager => new AliasManager($this->storage(), $this->engines(), $this->clock()));
    }

    /**
     * The schema lifecycle for one registered engine.
     */
    public function planner(string $engine): Planner
    {
        $this->engines()->get($engine);

        return $this->planners[$engine] ??= new Planner($engine, $this->engines(), $this->registry(), $this->aliases(), $this->storage(), $this->clock());
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
