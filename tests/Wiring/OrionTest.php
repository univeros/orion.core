<?php

declare(strict_types=1);

namespace Orion\Tests\Wiring;

use ArgumentCountError;
use DateTimeImmutable;
use InvalidArgumentException;
use Orion\IndexName;
use Orion\Orion;
use Orion\Registry\EngineRegistry;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Tenancy\TenantResolver;
use Orion\Tenancy\TenantScope;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use stdClass;
use TypeError;

final class OrionTest extends TestCase
{
    public function testCreateBuildsTheGraphFromTheRequiredPorts(): void
    {
        $storage = new InMemoryStorage();
        $engines = new EngineRegistry(default: new FakeEngine());
        $tenancy = Tenancy::single();

        $graph = Orion::create(new Config(storage: $storage, engines: $engines, tenancy: $tenancy))->graph();

        self::assertSame($storage, $graph->storage());
        self::assertSame($engines, $graph->engines());
        self::assertSame($tenancy, $graph->tenancy());
    }

    public function testEveryOtherPortHasACoreDefault(): void
    {
        $graph = Orion::create(self::config())->graph();

        self::assertInstanceOf(NullLogger::class, $graph->logger());
        self::assertSame($graph->clock(), $graph->clock());
        self::assertEqualsWithDelta(time(), $graph->clock()->now()->getTimestamp(), 5);
        $event = new stdClass();
        self::assertSame($event, $graph->events()->dispatch($event));
        $graph->cache()->set('key', 'value');
        self::assertSame('value', $graph->cache()->get('key'));
        self::assertSame(['api'], $graph->config()->manifestDirectories());
    }

    public function testAPortPassedInWinsOverTheDefault(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-25 00:00:00');
            }
        };

        $graph = Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(default: new FakeEngine()),
            tenancy: Tenancy::single(),
            clock: $clock,
            manifestDirectories: ['config/search'],
        ))->graph();

        self::assertSame($clock, $graph->clock());
        self::assertSame(['config/search'], $graph->config()->manifestDirectories());
    }

    public function testTheOutboxRecordsOnTheConfiguredStorage(): void
    {
        $storage = new InMemoryStorage();
        $graph = Orion::create(new Config(storage: $storage, engines: new EngineRegistry(default: new FakeEngine()), tenancy: Tenancy::single()))->graph();

        $graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp']);

        self::assertSame($graph->outbox(), $graph->outbox());
        self::assertSame(1, $storage->count(Schema::OUTBOX, []));
    }

    public function testBootingWithoutATenancyChoiceThrows(): void
    {
        $this->expectException(ArgumentCountError::class);

        /** @phpstan-ignore argument.missing */
        Orion::create(new Config(storage: new InMemoryStorage(), engines: new EngineRegistry(default: new FakeEngine())));
    }

    public function testANullTenancyIsNotAChoice(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        Orion::create(new Config(storage: new InMemoryStorage(), engines: new EngineRegistry(default: new FakeEngine()), tenancy: null));
    }

    public function testTenancyIsSingleOrAResolver(): void
    {
        $resolver = new class implements TenantResolver {
            public function resolve(): TenantScope
            {
                return new TenantScope('org_1');
            }
        };

        self::assertTrue(Tenancy::single()->isSingle());
        self::assertNull(Tenancy::single()->resolver);
        self::assertFalse(Tenancy::resolvedBy($resolver)->isSingle());
        self::assertSame($resolver, Tenancy::resolvedBy($resolver)->resolver);
    }

    public function testATenantScopeNeedsATenant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantScope('');
    }

    public function testEnginesAreLookedUpByName(): void
    {
        $engine = new FakeEngine();
        $engines = new EngineRegistry(elasticsearch: $engine);

        self::assertSame($engine, $engines->get('elasticsearch'));
        self::assertSame(['elasticsearch'], $engines->names());
    }

    public function testAnUnknownEngineFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('solr');

        (new EngineRegistry(elasticsearch: new FakeEngine()))->get('solr');
    }

    public function testAnEngineRegistryNeedsAnEngine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EngineRegistry();
    }

    public function testEnginesAreRegisteredByName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EngineRegistry(new FakeEngine());
    }

    public function testAnEngineMustImplementAllThreeRoles(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        new EngineRegistry(broken: new stdClass());
    }

    private static function config(): Config
    {
        return new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(default: new FakeEngine()),
            tenancy: Tenancy::single(),
        );
    }
}
