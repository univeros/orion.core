<?php

declare(strict_types=1);

namespace Orion\Receipt;

use InvalidArgumentException;
use Orion\Support\Assert;
use Override;

use function base64_decode;
use function preg_match;
use function sodium_crypto_sign_verify_detached;
use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_SIGN_BYTES;
use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;

/**
 * Ed25519 verification from public keys alone (`kid => public key`), for `orion verify` run by someone who must not
 * hold the signing key. Pass it as `Config::$receiptVerifier`.
 */
final readonly class SodiumVerifier implements ReceiptVerifier
{
    /**
     * @param array<string, string> $publicKeys kid => 32-byte Ed25519 public key
     */
    public function __construct(private array $publicKeys)
    {
        if ($publicKeys === []) {
            throw new InvalidArgumentException('A receipt verifier needs at least one public key.');
        }
        foreach ($publicKeys as $kid => $publicKey) {
            if (preg_match('/^[A-Za-z0-9._-]{1,64}\z/', (string) $kid) !== 1 || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException(sprintf('The public key of kid %s is not a %d-byte Ed25519 key under a valid kid.', Assert::show((string) $kid), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
            }
        }
    }

    /**
     * @param array<string, string> $publicKeys kid => base64 public key
     */
    public static function fromBase64(array $publicKeys): self
    {
        $decoded = [];
        foreach ($publicKeys as $kid => $publicKey) {
            $decoded[(string) $kid] = base64_decode($publicKey, true)
                ?: throw new InvalidArgumentException(sprintf('The public key of kid %s is not base64.', Assert::show((string) $kid)));
        }

        return new self($decoded);
    }

    #[Override]
    public function verify(string $message, string $signature, string $kid): bool
    {
        $publicKey = $this->publicKeys[$kid] ?? null;

        return $publicKey !== null && $publicKey !== '' && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
}
