<?php

declare(strict_types=1);

namespace Orion\Tests\Receipt;

use DateTimeImmutable;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Receipt\ReceiptStore;
use Orion\Receipt\SodiumSigner;
use Orion\Receipt\SodiumVerifier;
use Orion\Receipt\Verifier;
use Orion\Registry\IndexRegistration;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function strpos;
use function str_repeat;

final class VerifierTest extends TestCase
{
    private InMemoryStorage $storage;
    private SodiumSigner $signer;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->signer = SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1');
        $store = $this->store($this->signer);
        $store->append(Operation::Created, self::registration(1), null, 0, 0, 0);
        $store->append(Operation::Applied, self::registration(1), 1, 0, 0, 2);
        $store->append(Operation::Reindexed, self::registration(2), 1, 2, 0, 2);
    }

    public function testAnIntactChainVerifies(): void
    {
        $verification = $this->verifier($this->signer)->verify();

        $head = $this->store($this->signer)->latest();
        self::assertTrue($verification->ok());
        self::assertSame(['receipts' => 3, 'ok' => true, 'breaks' => [], 'head' => ['sequence' => 3, 'hash' => $head?->hash]], $verification->toArray());
    }

    public function testAnAlteredReceiptIsCaught(): void
    {
        $this->storage->update(Schema::RECEIPT, ['sequence' => 2], ['document_count' => 99]);

        self::assertSame(['receipt 2 was altered: its hash does not match its contents'], $this->verifier($this->signer)->verify()->breaks);
    }

    public function testARehashedButUnsignedAlterationIsCaughtByItsSignature(): void
    {
        $forger = $this->store(SodiumSigner::fromSeed(str_repeat("\x09", 32), 'k1'));
        $this->storage->delete(Schema::RECEIPT, ['sequence' => 3]);
        $this->storage->update(Schema::COUNTER, ['name' => 'receipt'], ['value' => 2]);
        $forger->append(Operation::Reindexed, self::registration(2), 1, 999, 0, 2);

        self::assertSame(['receipt 3 has a signature that does not verify under kid "k1"'], $this->verifier($this->signer)->verify()->breaks);
    }

    public function testARemovedReceiptBreaksTheChain(): void
    {
        $this->storage->delete(Schema::RECEIPT, ['sequence' => 2]);

        self::assertSame(['receipt 3 follows receipt 1: receipt 2 is missing', 'receipt 3 does not follow the previous receipt\'s hash'], $this->verifier($this->signer)->verify()->breaks);
    }

    public function testATruncatedChainIsCaughtAgainstTheSequence(): void
    {
        $this->storage->delete(Schema::RECEIPT, ['sequence' => 3]);

        self::assertSame(['the chain ends at receipt 2 but 3 were issued'], $this->verifier($this->signer)->verify()->breaks);
    }

    public function testAReceiptSignedWithAnUnknownKidIsCaught(): void
    {
        $other = SodiumSigner::fromSeed(str_repeat("\x02", 32), 'k2');

        self::assertCount(3, $this->verifier($other)->verify()->breaks);
        self::assertSame('receipt 1 has a signature that does not verify under kid "k1"', $this->verifier($other)->verify()->breaks[0]);
    }

    public function testReceiptsSignedBeforeARotationVerifyWithThePreviousPublicKey(): void
    {
        $rotated = SodiumSigner::fromSeed(str_repeat("\x02", 32), 'k2', ['k1' => $this->signer->publicKey()]);
        $this->store($rotated)->append(Operation::Applied, self::registration(2), 2, 0, 2, 2);

        self::assertTrue($this->verifier($rotated)->verify()->ok());
        self::assertSame(4, $this->verifier($rotated)->verify()->receipts);
    }

    public function testASignatureThatIsNotBase64IsCaught(): void
    {
        $this->storage->update(Schema::RECEIPT, ['sequence' => 1], ['signature' => '***']);

        self::assertSame(['receipt 1 has a signature that does not verify under kid "k1"'], $this->verifier($this->signer)->verify()->breaks);
    }

    public function testThePublicKeysAloneVerifyTheChain(): void
    {
        $verifier = new Verifier($this->store($this->signer), SodiumVerifier::fromBase64(['k1' => base64_encode($this->signer->publicKey())]));

        self::assertTrue($verifier->verify()->ok());
        self::assertCount(3, (new Verifier($this->store($this->signer), SodiumVerifier::fromBase64(['k9' => base64_encode($this->signer->publicKey())])))->verify()->breaks);
    }

    public function testAnAnchoredHeadCatchesATailCutTogetherWithTheSequence(): void
    {
        $head = $this->store($this->signer)->latest();
        $this->storage->delete(Schema::RECEIPT, ['sequence' => 3]);
        $this->storage->update(Schema::COUNTER, ['name' => 'receipt'], ['value' => 2]);

        self::assertTrue($this->verifier($this->signer)->verify()->ok(), 'Without an anchor the cut is invisible.');
        self::assertSame(['the chain does not reach the anchored receipt 3'], $this->verifier($this->signer)->verify(3, (string) $head?->hash)->breaks);
        self::assertSame(['receipt 2 does not match the anchored hash'], $this->verifier($this->signer)->verify(2, str_repeat('0', 64))->breaks);
    }

    public function testASignatureReencodedOutsideCanonicalBase64IsCaught(): void
    {
        $signature = (string) $this->store($this->signer)->find(1)?->signature;
        // 64 bytes leave four unused bits in the last base64 character; setting one decodes to the same signature.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $last = strpos($alphabet, $signature[85]);
        $signature[85] = $alphabet[$last ^ 1];
        $this->storage->update(Schema::RECEIPT, ['sequence' => 1], ['signature' => $signature]);

        self::assertSame(['receipt 1 has a signature that does not verify under kid "k1"'], $this->verifier($this->signer)->verify()->breaks);
    }

    public function testAnEmptyChainVerifies(): void
    {
        $verifier = new Verifier(new ReceiptStore(new InMemoryStorage(), null, new FixedClock()), $this->signer);

        self::assertSame(['receipts' => 0, 'ok' => true, 'breaks' => [], 'head' => null], $verifier->verify()->toArray());
        self::assertSame(base64_encode($this->signer->publicKey()), base64_encode($this->signer->publicKey()));
    }

    private function store(SodiumSigner $signer): ReceiptStore
    {
        return new ReceiptStore($this->storage, $signer, new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')));
    }

    private function verifier(SodiumSigner $signer): Verifier
    {
        return new Verifier($this->store($signer), $signer);
    }

    private static function registration(int $version): IndexRegistration
    {
        return new IndexRegistration(IndexName::of('products_v' . $version), IndexName::of('products'), 'memory', $version, IndexSchema::for('products')->text('title'), new DateTimeImmutable('2026-10-26 12:00:00+00:00'));
    }
}
