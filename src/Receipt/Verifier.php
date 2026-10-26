<?php

declare(strict_types=1);

namespace Orion\Receipt;

use Orion\Lifecycle\AliasManager;
use Orion\Lifecycle\Operation;
use Orion\Support\Assert;
use Throwable;

use function base64_decode;
use function base64_encode;
use function hash;
use function hash_equals;
use function in_array;
use function sprintf;
use function substr;

/**
 * Walks the receipt chain (`orion verify`): each receipt's hash must be its message's, its sequence must follow the
 * one before, its `previous_hash` must be that receipt's hash, its signature must verify under its kid (canonical
 * base64), and the chain must reach the last sequence number issued. Two checks reach outside the chain: an anchored
 * head (a sequence and hash recorded somewhere the database's writers cannot rewrite) catches a tail cut together
 * with the sequence counter, and, given the alias manager, every alias's current version and schema must be what its
 * last receipt attests, so a change made with receipts off, or written into the registry by hand, is reported.
 */
final readonly class Verifier
{
    private const array ATTESTING = [Operation::Created, Operation::Applied, Operation::Reindexed];

    public function __construct(private ReceiptStore $store, private ReceiptVerifier $keys, private ?AliasManager $aliases = null)
    {
    }

    public function verify(?int $anchorSequence = null, ?string $anchorHash = null): Verification
    {
        $breaks = [];
        $count = 0;
        $previous = null;
        $anchored = null;
        /** @var array<string, Receipt> $attested */
        $attested = [];
        try {
            foreach ($this->store->walk() as $receipt) {
                ++$count;
                foreach ($this->check($receipt, $previous) as $break) {
                    $breaks[] = $break;
                }
                if (in_array($receipt->operation, self::ATTESTING, true)) {
                    $attested[$receipt->index->value] = $receipt;
                }
                if ($receipt->sequence === $anchorSequence) {
                    $anchored = $receipt;
                }
                $previous = $receipt;
            }
        } catch (Throwable $unreadable) {
            $breaks[] = sprintf('the receipt after %d cannot be read: %s', $previous->sequence ?? 0, $unreadable->getMessage());
        }
        $issued = $this->store->head();
        if (($previous->sequence ?? 0) < $issued) {
            $breaks[] = sprintf('the chain ends at receipt %d but %d were issued', $previous->sequence ?? 0, $issued);
        }
        if ($anchorSequence !== null) {
            if ($anchored === null) {
                $breaks[] = sprintf('the chain does not reach the anchored receipt %d', $anchorSequence);
            } elseif (!hash_equals($anchored->hash, (string) $anchorHash)) {
                $breaks[] = sprintf('receipt %d does not match the anchored hash', $anchorSequence);
            }
        }
        foreach ($this->unattested($attested) as $break) {
            $breaks[] = $break;
        }

        return new Verification($count, $breaks, $previous);
    }

    /**
     * @return list<string>
     */
    private function check(Receipt $receipt, ?Receipt $previous): array
    {
        $breaks = [];
        $expected = ($previous->sequence ?? 0) + 1;
        if ($receipt->sequence !== $expected) {
            $breaks[] = $receipt->sequence - $expected === 1
                ? sprintf('receipt %d follows receipt %d: receipt %d is missing', $receipt->sequence, $expected - 1, $expected)
                : sprintf('receipt %d follows receipt %d: receipts %d to %d are missing', $receipt->sequence, $expected - 1, $expected, $receipt->sequence - 1);
        }
        if ($receipt->previousHash !== $previous?->hash) {
            $breaks[] = sprintf('receipt %d does not follow the previous receipt\'s hash', $receipt->sequence);
        }
        $message = $receipt->message();
        if (!hash_equals($receipt->hash, hash('sha256', $message))) {
            // An altered receipt's signature fails as well; one break says it.
            return [sprintf('receipt %d was altered: its hash does not match its contents', $receipt->sequence), ...$breaks];
        }
        $signature = base64_decode($receipt->signature, true);
        if ($signature === false || base64_encode($signature) !== $receipt->signature || !$this->keys->verify($message, $signature, $receipt->kid)) {
            $breaks[] = sprintf('receipt %d has a signature that does not verify under kid %s', $receipt->sequence, Assert::show($receipt->kid));
        }

        return $breaks;
    }

    /**
     * @param array<string, Receipt> $attested the last create, apply or reindex receipt of each alias
     * @return list<string>
     */
    private function unattested(array $attested): array
    {
        if ($this->aliases === null) {
            return [];
        }
        $breaks = [];
        foreach ($this->aliases->aliases() as $alias) {
            try {
                $current = $this->aliases->current($alias);
            } catch (Throwable $unreadable) {
                $breaks[] = sprintf('the alias %s cannot be read: %s', Assert::show($alias->value), $unreadable->getMessage());
                continue;
            }
            $last = $attested[$alias->value] ?? null;
            if ($current === null || ($last !== null && $last->engine === $current->engine && $last->toVersion === $current->version && $last->schemaHash === $current->schema->hash())) {
                continue;
            }
            $breaks[] = sprintf(
                '%s is at version %d with schema %s, but %s',
                Assert::show($alias->value),
                $current->version,
                substr($current->schema->hash(), 0, 12),
                $last === null ? 'no receipt attests it' : sprintf('its last receipt attests version %d with schema %s', $last->toVersion, substr($last->schemaHash, 0, 12)),
            );
        }

        return $breaks;
    }
}
