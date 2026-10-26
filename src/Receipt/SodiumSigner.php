<?php

declare(strict_types=1);

namespace Orion\Receipt;

use InvalidArgumentException;
use LogicException;
use Orion\Support\Assert;
use Override;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function preg_match;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_publickey_from_secretkey;
use function sodium_crypto_sign_secretkey;
use function sodium_crypto_sign_seed_keypair;
use function sodium_crypto_sign_verify_detached;
use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_SIGN_BYTES;
use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
use const SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
use const SODIUM_CRYPTO_SIGN_SEEDBYTES;

/**
 * Ed25519 through libsodium, the core's {@see ReceiptSigner}. The host supplies the key: a secret key (or its seed)
 * and its kid, plus the public keys of earlier kids so receipts signed before a rotation still verify.
 * `orion receipts:keygen` prints a new pair.
 */
final readonly class SodiumSigner implements ReceiptSigner
{
    /** @var non-empty-string */
    private string $secretKey;
    /** @var array<string, string> kid => public key */
    private array $publicKeys;

    /**
     * @param string $secretKey the 64-byte Ed25519 secret key
     * @param array<string, string> $previousPublicKeys kid => 32-byte public key
     */
    public function __construct(#[SensitiveParameter] string $secretKey, private string $kid, array $previousPublicKeys = [])
    {
        if ($secretKey === '' || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException(sprintf('An Ed25519 secret key is %d bytes.', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES));
        }
        $this->secretKey = $secretKey;
        self::assertKid($kid);
        foreach ($previousPublicKeys as $previous => $publicKey) {
            self::assertKid((string) $previous);
            if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException(sprintf('The public key of kid %s is not %d bytes.', Assert::show((string) $previous), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
            }
        }
        $this->publicKeys = [...$previousPublicKeys, $kid => sodium_crypto_sign_publickey_from_secretkey($secretKey)];
    }

    /**
     * @param array<string, string> $previousPublicKeys kid => 32-byte public key
     */
    public static function fromSeed(#[SensitiveParameter] string $seed, string $kid, array $previousPublicKeys = []): self
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException(sprintf('An Ed25519 seed is %d bytes.', SODIUM_CRYPTO_SIGN_SEEDBYTES));
        }

        return new self(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)), $kid, $previousPublicKeys);
    }

    /**
     * The form keys travel in (an environment variable, a secret store): base64.
     *
     * @param array<string, string> $previousPublicKeys kid => base64 public key
     */
    public static function fromBase64(#[SensitiveParameter] string $secretKey, string $kid, array $previousPublicKeys = []): self
    {
        $previous = [];
        foreach ($previousPublicKeys as $previousKid => $publicKey) {
            $previous[(string) $previousKid] = self::decode($publicKey, 'public key of kid ' . Assert::show((string) $previousKid));
        }

        return new self(self::decode($secretKey, 'secret key'), $kid, $previous);
    }

    /**
     * @return array{secret_key: string, public_key: string} base64
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))];
    }

    #[Override]
    public function kid(): string
    {
        return $this->kid;
    }

    public function publicKey(): string
    {
        return $this->publicKeys[$this->kid];
    }

    #[Override]
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    #[Override]
    public function verify(string $message, string $signature, string $kid): bool
    {
        $publicKey = $this->publicKeys[$kid] ?? null;

        return $publicKey !== null && $publicKey !== '' && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    /**
     * A signer holds a secret key; it is never serialized, so it cannot end up in a cache or a session.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('A receipt signer holds a secret key and cannot be serialized.');
    }

    /**
     * @return array{kid: string}
     */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid];
    }

    private static function assertKid(string $kid): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $kid) !== 1) {
            throw new InvalidArgumentException(sprintf('A kid is 1 to 64 characters of letters, digits, ".", "_" and "-", not %s.', Assert::show($kid)));
        }
    }

    private static function decode(#[SensitiveParameter] string $value, string $what): string
    {
        $decoded = base64_decode($value, true);

        return $decoded === false ? throw new InvalidArgumentException(sprintf('The %s is not base64.', $what)) : $decoded;
    }
}
