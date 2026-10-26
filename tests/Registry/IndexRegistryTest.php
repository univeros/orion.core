<?php

declare(strict_types=1);

namespace Orion\Tests\Registry;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Orion\IndexName;
use Orion\Registry\IndexRegistration;
use Orion\Registry\IndexRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Testing\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;
use function json_encode;

final class IndexRegistryTest extends TestCase
{
    private InMemoryStorage $storage;
    private IndexRegistry $registry;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->registry = new IndexRegistry($this->storage);
    }

    public function testARegistrationIsFoundByItsPhysicalIndex(): void
    {
        $registration = self::registration(1);

        $this->registry->register($registration);

        self::assertEquals($registration, $this->registry->find(IndexName::of('products_v1')));
        self::assertNull($this->registry->find(IndexName::of('products_v2')));
    }

    public function testRegisteringAPhysicalIndexTwiceFails(): void
    {
        $this->registry->register(self::registration(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('products_v1');

        $this->registry->register(self::registration(1, self::schema()->keyword('brand')));
    }

    public function testUpdatingReplacesTheDefinitionOfARegisteredIndexOnly(): void
    {
        $this->registry->register(self::registration(1));
        $changed = self::registration(1, self::schema()->keyword('brand'));

        $this->registry->update($changed);

        self::assertSame(1, $this->storage->count(Schema::INDEX, []));
        self::assertEquals($changed, $this->registry->find(IndexName::of('products_v1')));
        self::assertSame($changed->schema->hash(), $this->storage->findOne(Schema::INDEX, ['name' => 'products_v1'])['schema_hash'] ?? null);

        $this->expectException(LogicException::class);

        $this->registry->update(self::registration(2));
    }

    public function testVersionsAreListedInOrderAndTheNextOneFollowsTheHighest(): void
    {
        self::assertSame(1, $this->registry->nextVersion(IndexName::of('products')));
        $this->registry->register(self::registration(3));
        $this->registry->register(self::registration(1));
        $this->registry->register(new IndexRegistration(IndexName::of('other_v9'), IndexName::of('other'), 'in-memory', 9, IndexSchema::for('other')->keyword('id'), self::now()));

        self::assertSame([1, 3], array_map(static fn(IndexRegistration $r): int => $r->version, $this->registry->versions(IndexName::of('products'))));
        self::assertSame(4, $this->registry->nextVersion(IndexName::of('products')));
    }

    public function testForgettingRemovesThePhysicalIndexOnly(): void
    {
        $this->registry->register(self::registration(1));
        $this->registry->register(self::registration(2));

        $this->registry->forget(IndexName::of('products_v1'));

        self::assertNull($this->registry->find(IndexName::of('products_v1')));
        self::assertNotNull($this->registry->find(IndexName::of('products_v2')));
    }

    public function testTheRowRoundTripsAndRejectsUnknownKeys(): void
    {
        $registration = self::registration(2);

        $row = $registration->toRow();

        self::assertSame(['name', 'alias', 'engine', 'version', 'definition', 'schema_hash', 'created_at'], array_keys($row));
        self::assertSame($registration->schema->toArray(), json_decode((string) $row['definition'], true));
        self::assertEquals($registration, IndexRegistration::fromRow($row));
        // Drivers return strings for what they stored.
        self::assertEquals($registration, IndexRegistration::fromRow([...$row, 'version' => '2', 'created_at' => '2026-10-26 10:00:00.000000']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('extra');

        IndexRegistration::fromRow([...$row, 'extra' => 1]);
    }

    public function testATamperedDefinitionFailsClosed(): void
    {
        $row = self::registration(1)->toRow();
        $row['definition'] = json_encode([...self::schema()->keyword('injected')->toArray()]);

        $this->expectException(LogicException::class);

        IndexRegistration::fromRow($row);
    }

    public function testAPhysicalIndexMustBelongToItsAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IndexRegistration(IndexName::of('other_v1'), IndexName::of('products'), 'in-memory', 1, self::schema(), self::now());
    }

    public function testTheSchemaMustBeDeclaredForTheAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'in-memory', 1, IndexSchema::for('other'), self::now());
    }

    private static function registration(int $version, ?IndexSchema $schema = null): IndexRegistration
    {
        return new IndexRegistration(IndexName::of('products_v' . $version), IndexName::of('products'), 'in-memory', $version, $schema ?? self::schema(), self::now());
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')->text('title', language: 'en')->keyword('sku', filterable: true)->orgKey('org_id')->native('elasticsearch', ['settings' => ['a' => 1]]);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-26 10:00:00');
    }
}
