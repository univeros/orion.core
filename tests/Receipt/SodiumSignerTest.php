<?php

declare(strict_types=1);

namespace Orion\Tests\Receipt;

use InvalidArgumentException;
use Orion\Receipt\SodiumSigner;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function str_repeat;
use function strlen;

final class SodiumSignerTest extends TestCase
{
    public function testASignatureVerifiesUnderItsKidAndIsDeterministic(): void
    {
        $signer = SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1');

        $signature = $signer->sign('message');

        self::assertSame('k1', $signer->kid());
        self::assertSame(64, strlen($signature));
        self::assertSame($signature, $signer->sign('message'));
        self::assertTrue($signer->verify('message', $signature, 'k1'));
        self::assertFalse($signer->verify('altered', $signature, 'k1'));
        self::assertFalse($signer->verify('message', $signature, 'k0'));
        self::assertFalse($signer->verify('message', 'short', 'k1'));
    }

    public function testAPreviousKeyStillVerifiesWhatItSigned(): void
    {
        $old = SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1');
        $new = SodiumSigner::fromSeed(str_repeat("\x02", 32), 'k2', ['k1' => $old->publicKey()]);

        self::assertTrue($new->verify('message', $old->sign('message'), 'k1'));
        self::assertFalse($new->verify('message', $old->sign('message'), 'k2'));
    }

    public function testAGeneratedKeyPairLoadsFromBase64(): void
    {
        $pair = SodiumSigner::generateKeyPair();
        $signer = SodiumSigner::fromBase64($pair['secret_key'], 'k1', ['k0' => $pair['public_key']]);

        self::assertSame(64, strlen((string) base64_decode($pair['secret_key'], true)));
        self::assertSame($pair['public_key'], \base64_encode($signer->publicKey()));
        self::assertTrue($signer->verify('m', $signer->sign('m'), 'k0'));
    }

    public function testASignerCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        \serialize(SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1'));
    }

    public function testAVerifierRefusesMalformedPublicKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        \Orion\Receipt\SodiumVerifier::fromBase64(['k1' => \base64_encode('short')]);
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'short secret key' => [static fn() => new SodiumSigner('short', 'k1')];
        yield 'short seed' => [static fn() => SodiumSigner::fromSeed('short', 'k1')];
        yield 'empty kid' => [static fn() => SodiumSigner::fromSeed(str_repeat("\x01", 32), '')];
        yield 'kid with spaces' => [static fn() => SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k 1')];
        yield 'kid longer than the column' => [static fn() => SodiumSigner::fromSeed(str_repeat("\x01", 32), str_repeat('k', 65))];
        yield 'short previous public key' => [static fn() => SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k2', ['k1' => 'short'])];
        yield 'secret not base64' => [static fn() => SodiumSigner::fromBase64('not base64!', 'k1')];
    }

    /**
     * @param callable(): mixed $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testInvalidKeyMaterialIsRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }
}
