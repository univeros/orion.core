<?php

declare(strict_types=1);

namespace Orion\Support;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\SimpleCache\CacheInterface;

use function preg_match;
use function preg_quote;
use function sprintf;
use function time;

/**
 * The default PSR-16 cache: an in-process array with per-entry TTL. Each PHP worker holds its own and loses it at
 * the end of the request, so a production host binds a shared cache.
 */
final class InMemoryCache implements CacheInterface
{
    private const string RESERVED_KEY_CHARACTERS = '{}()/\\@:';

    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $store = [];

    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);

        return $this->isFresh($key) ? $this->store[$key]['value'] : $default;
    }

    #[Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->assertValidKey($key);
        $seconds = $this->ttlInSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            unset($this->store[$key]);

            return true;
        }
        $this->store[$key] = ['value' => $value, 'expiresAt' => $seconds === null ? null : time() + $seconds];

        return true;
    }

    #[Override]
    public function delete(string $key): bool
    {
        $this->assertValidKey($key);
        unset($this->store[$key]);

        return true;
    }

    #[Override]
    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return array<string, mixed>
     */
    #[Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    #[Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        $this->assertValidKey($key);

        return $this->isFresh($key);
    }

    private function isFresh(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }
        $expiresAt = $this->store[$key]['expiresAt'];
        if ($expiresAt !== null && $expiresAt <= time()) {
            unset($this->store[$key]);

            return false;
        }

        return true;
    }

    private function ttlInSeconds(null|int|DateInterval $ttl): ?int
    {
        if (!$ttl instanceof DateInterval) {
            return $ttl;
        }
        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }

    private function assertValidKey(string $key): void
    {
        if ($key === '' || preg_match('/[' . preg_quote(self::RESERVED_KEY_CHARACTERS, '/') . ']/', $key) === 1) {
            throw new InvalidCacheKey(sprintf('"%s" is not a valid cache key: it must be non-empty and free of {}()/\\@:.', $key));
        }
    }
}
