<?php

declare(strict_types=1);

namespace Orion\Wiring;

use Orion\Observability\Metrics;
use Orion\Outbox\Dispatcher;
use Orion\Receipt\ReceiptSigner;
use Orion\Registry\EngineRegistry;
use Orion\Storage\Storage;
use Orion\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Everything an application hands Orion (docs/spec/revision.md §3). Storage, engines and tenancy are a
 * correctness or trust boundary and have no default; anything null takes the core default. Receipts need key
 * material only the host has: without a `receiptSigner` the lifecycle runs and issues none.
 */
final readonly class Config
{
    public const string DEFAULT_MANIFEST_DIRECTORY = 'api';

    /**
     * @param list<string>|null $manifestDirectories where `search:` manifests are loaded from
     */
    public function __construct(
        public Storage $storage,
        public EngineRegistry $engines,
        public Tenancy $tenancy,
        public ?ClockInterface $clock = null,
        public ?LoggerInterface $logger = null,
        public ?EventDispatcherInterface $events = null,
        public ?CacheInterface $cache = null,
        public ?array $manifestDirectories = null,
        public ?Dispatcher $dispatcher = null,
        public ?Metrics $metrics = null,
        public ?ReceiptSigner $receiptSigner = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function manifestDirectories(): array
    {
        return $this->manifestDirectories ?? [self::DEFAULT_MANIFEST_DIRECTORY];
    }
}
