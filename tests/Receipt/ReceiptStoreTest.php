<?php

declare(strict_types=1);

namespace Orion\Tests\Receipt;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Receipt\Receipt;
use Orion\Receipt\ReceiptStore;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\IndexRegistration;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Support\Canonical;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_decode;
use function hash;
use function iterator_to_array;
use function str_repeat;

final class ReceiptStoreTest extends TestCase
{
    private InMemoryStorage $storage;
    private SodiumSigner $signer;
    private ReceiptStore $store;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->signer = SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1');
        $this->store = new ReceiptStore($this->storage, $this->signer, new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')));
    }

    public function testReceiptsFormAChainOfSignedHashes(): void
    {
        $first = $this->store->append(Operation::Created, self::registration(1), null, 0, 0, 0);
        $second = $this->store->append(Operation::Reindexed, self::registration(2), 1, 3, 0, 7);

        self::assertSame([1, null], [$first->sequence, $first->previousHash]);
        self::assertSame([2, $first->hash], [$second->sequence, $second->previousHash]);
        self::assertSame(hash('sha256', $second->message()), $second->hash);
        self::assertTrue($this->signer->verify($second->message(), (string) base64_decode($second->signature, true), 'k1'));
        self::assertSame(['products', 'memory', 1, 2, 3, 0, 7, 'k1'], [$second->index->value, $second->engine, $second->fromVersion, $second->toVersion, $second->documentCount, $second->fromOffset, $second->toOffset, $second->kid]);
        self::assertSame(self::registration(2)->schema->hash(), $second->schemaHash);
    }

    public function testTheMessageIsTheCanonicalPayloadWithoutHashOrSignature(): void
    {
        $receipt = $this->store->append(Operation::Created, self::registration(1), null, 0, 0, 0);

        self::assertSame(Canonical::json([
            'document_count' => 0,
            'engine' => 'memory',
            'from_offset' => 0,
            'from_version' => null,
            'index' => 'products',
            'kid' => 'k1',
            'operation' => 'created',
            'previous_hash' => null,
            'recorded_at' => '2026-10-26T12:00:00.000000+00:00',
            'schema_hash' => self::registration(1)->schema->hash(),
            'sequence' => 1,
            'to_offset' => 0,
            'to_version' => 1,
        ]), $receipt->message());
    }

    public function testAReceiptRoundTripsThroughItsRowAsADriverReturnsIt(): void
    {
        $receipt = $this->store->append(Operation::Created, self::registration(1), null, 0, 0, 0);
        $row = $receipt->toRow();
        $driver = array_map(static fn(mixed $v): mixed => \is_int($v) ? (string) $v : $v, [...$row, 'recorded_at' => '2026-10-26 12:00:00']);

        self::assertEquals($receipt, Receipt::fromRow($row));
        self::assertEquals($receipt, Receipt::fromRow($driver));
        self::assertSame($receipt->message(), Receipt::fromRow($driver)->message());
        $this->expectException(InvalidArgumentException::class);
        Receipt::fromRow([...$row, 'extra' => 1]);
    }

    public function testTheChainIsReadInOrder(): void
    {
        foreach ([1, 2, 3] as $version) {
            $this->store->append(Operation::Created, self::registration($version), null, 0, 0, 0);
        }

        self::assertSame([1, 2, 3], array_map(static fn(Receipt $r): int => $r->sequence, iterator_to_array($this->store->walk(batch: 2), false)));
        self::assertSame(3, $this->store->latest()?->sequence);
        self::assertSame(3, $this->store->head());
        self::assertSame(3, $this->store->count(IndexName::of('products')));
        self::assertSame(0, $this->store->count(IndexName::of('orders')));
        self::assertSame([3], array_map(static fn(Receipt $r): int => $r->sequence, $this->store->of(IndexName::of('products'), limit: 1)));
    }

    public function testAChainWhosePreviousReceiptIsMissingIsNotExtended(): void
    {
        $this->store->append(Operation::Created, self::registration(1), null, 0, 0, 0);
        $this->storage->delete(Schema::RECEIPT, ['sequence' => 1]);

        $this->expectException(LogicException::class);

        $this->store->append(Operation::Applied, self::registration(1), 1, 0, 0, 0);
    }

    public function testAReceiptCannotBeAppendedWithoutASigner(): void
    {
        $store = new ReceiptStore($this->storage, null, new FixedClock());

        $this->expectException(LogicException::class);

        $store->append(Operation::Created, self::registration(1), null, 0, 0, 0);
    }

    private static function registration(int $version): IndexRegistration
    {
        return new IndexRegistration(
            IndexName::of('products_v' . $version),
            IndexName::of('products'),
            'memory',
            $version,
            IndexSchema::for('products')->text('title'),
            new DateTimeImmutable('2026-10-26 12:00:00+00:00'),
        );
    }
}
