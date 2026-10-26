<?php

declare(strict_types=1);

namespace Orion\Tests\Lifecycle;

use DateTimeImmutable;
use InvalidArgumentException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Lifecycle\AliasManager;
use Orion\Registry\EngineRegistry;
use Orion\Storage\Schema;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AliasManagerTest extends TestCase
{
    private InMemoryStorage $storage;
    private AliasFakeEngine $native;
    private AliasManager $aliases;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->native = new AliasFakeEngine();
        $this->aliases = new AliasManager($this->storage, new EngineRegistry(native: $this->native, emulated: new InMemoryEngine()), new FixedClock(new DateTimeImmutable('2026-10-26 10:00:00')));
    }

    public function testAnUnknownAliasResolvesToNothing(): void
    {
        self::assertNull($this->aliases->current(IndexName::of('products')));
    }

    public function testASwapRecordsThePhysicalIndexAndPointsTheNativeAlias(): void
    {
        $this->aliases->swap('native', IndexName::of('products'), IndexName::of('products_v1'));
        $this->aliases->swap('native', IndexName::of('products'), IndexName::of('products_v2'));

        self::assertSame('products_v2', $this->aliases->current(IndexName::of('products'))?->value);
        self::assertSame(['products' => 'products_v2'], $this->native->aliases);
        self::assertSame(1, $this->storage->count(Schema::ALIAS, []));
        self::assertEquals([
            'alias' => 'products',
            'engine' => 'native',
            'physical' => 'products_v2',
            'swapped_at' => new DateTimeImmutable('2026-10-26 10:00:00'),
        ], $this->storage->findOne(Schema::ALIAS, ['alias' => 'products']));
    }

    public function testAnEngineWithoutNativeAliasesIsNotAsked(): void
    {
        $this->aliases->swap('emulated', IndexName::of('products'), IndexName::of('products_v1'));

        self::assertSame('products_v1', $this->aliases->current(IndexName::of('products'))?->value);
        self::assertSame([], $this->native->aliases);
    }

    public function testTheTargetIsTheAliasOnANativeEngineAndThePhysicalIndexOtherwise(): void
    {
        $this->aliases->swap('native', IndexName::of('products'), IndexName::of('products_v1'));
        $this->aliases->swap('emulated', IndexName::of('orders'), IndexName::of('orders_v4'));

        self::assertSame('products', $this->aliases->target(IndexName::of('products'))->value);
        self::assertSame('orders_v4', $this->aliases->target(IndexName::of('orders'))->value);
    }

    public function testAnUnknownAliasHasNoTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->aliases->target(IndexName::of('missing'));
    }

    public function testARefusedNativeSwapRecordsNothing(): void
    {
        $this->aliases->swap('native', IndexName::of('products'), IndexName::of('products_v1'));
        $this->native->failNextAlias = true;

        try {
            $this->aliases->swap('native', IndexName::of('products'), IndexName::of('products_v2'));
            self::fail('The refusal was swallowed.');
        } catch (RuntimeException) {
            self::assertSame('products_v1', $this->aliases->current(IndexName::of('products'))?->value);
            self::assertSame(['products' => 'products_v1'], $this->native->aliases);
        }
    }

    public function testAnAliasBelongsToOneEngine(): void
    {
        $this->aliases->swap('native', IndexName::of('products'), IndexName::of('products_v1'));

        $this->expectException(InvalidArgumentException::class);

        $this->aliases->swap('emulated', IndexName::of('products'), IndexName::of('products_v2'));
    }
}
