<?php

declare(strict_types=1);

namespace Orion\Receipt;

use Closure;
use LogicException;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Registry\IndexRegistration;
use Orion\Storage\Condition;
use Orion\Storage\Counters;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Psr\Clock\ClockInterface;

use function array_map;
use function count;
use function sprintf;

/**
 * The append-only receipt chain in `orion_receipt` (docs/spec/revision.md §3). It appends and reads; nothing here
 * updates or deletes a receipt. Sequence numbers come from the `receipt` counter, whose row stays locked until the
 * appending transaction ends, so concurrent appends queue, each chains to the one committed before it, and the chain
 * never forks.
 */
final readonly class ReceiptStore
{
    private const string SEQUENCE = 'receipt';

    private Counters $counters;

    public function __construct(private Storage $storage, private ?ReceiptSigner $signer, private ClockInterface $clock)
    {
        $this->counters = new Counters($storage);
    }

    /**
     * Appends the receipt. Call it first in the transaction that records the operation: it takes the sequence lock and
     * then reads the receipt before it, and under MySQL's repeatable read an earlier read in the same transaction would
     * fix a snapshot in which a receipt committed meanwhile is missing.
     *
     * @throws LogicException without a signer, or when the receipt before it is missing (the chain is broken: verify it)
     */
    public function append(Operation $operation, IndexRegistration $to, ?int $fromVersion, int $documentCount, int $fromOffset, int $toOffset): Receipt
    {
        return $this->issue($operation, $to, $fromVersion, $documentCount, static fn(): array => [$fromOffset, $toOffset]);
    }

    /**
     * {@see append()} for an operation at an outbox position: `$head` is read after the sequence lock is taken, so the
     * receipt's offsets come from the same snapshot as the chain.
     *
     * @param Closure(): int $head
     */
    public function appendAtHead(Operation $operation, IndexRegistration $to, ?int $fromVersion, int $documentCount, Closure $head): Receipt
    {
        return $this->issue($operation, $to, $fromVersion, $documentCount, static function () use ($head): array {
            $offset = $head();

            return [$offset, $offset];
        });
    }

    /**
     * @param Closure(): array{int, int} $offsets
     */
    private function issue(Operation $operation, IndexRegistration $to, ?int $fromVersion, int $documentCount, Closure $offsets): Receipt
    {
        $signer = $this->signer ?? throw new LogicException('Receipts are signed: pass a ReceiptSigner in the Config.');

        return $this->storage->transaction(function () use ($signer, $operation, $to, $fromVersion, $documentCount, $offsets): Receipt {
            $sequence = $this->counters->next(self::SEQUENCE);
            $previous = $sequence === 1 ? null : ($this->find($sequence - 1)
                ?? throw new LogicException(sprintf('Receipt %d is missing, so the chain cannot be extended; run orion verify.', $sequence - 1)));
            [$fromOffset, $toOffset] = $offsets();
            $receipt = Receipt::issue($sequence, $operation, $to, $fromVersion, $documentCount, $fromOffset, $toOffset, $previous?->hash, $this->clock->now(), $signer);
            $this->storage->insert(Schema::RECEIPT, $receipt->toRow());

            return $receipt;
        });
    }

    public function find(int $sequence): ?Receipt
    {
        $row = $this->storage->findOne(Schema::RECEIPT, ['sequence' => $sequence]);

        return $row === null ? null : Receipt::fromRow($row);
    }

    public function latest(): ?Receipt
    {
        $rows = $this->storage->findMany(Schema::RECEIPT, [], ['sequence' => 'desc'], 1);

        return $rows === [] ? null : Receipt::fromRow($rows[0]);
    }

    /**
     * How many receipts were issued: the last sequence number handed out.
     */
    public function head(): int
    {
        return $this->counters->value(self::SEQUENCE);
    }

    public function count(IndexName $index): int
    {
        return $this->storage->count(Schema::RECEIPT, ['index_name' => $index->value]);
    }

    /**
     * @return list<Receipt> the index's receipts, newest first
     */
    public function of(IndexName $index, ?int $limit = null): array
    {
        return array_map(Receipt::fromRow(...), $this->storage->findMany(Schema::RECEIPT, ['index_name' => $index->value], ['sequence' => 'desc'], $limit));
    }

    /**
     * Every receipt in chain order, read in batches.
     *
     * @return iterable<Receipt>
     */
    public function walk(int $batch = 500): iterable
    {
        $after = 0;
        do {
            $rows = $this->storage->findMany(Schema::RECEIPT, ['sequence' => Condition::gt($after)], ['sequence' => 'asc'], $batch);
            foreach ($rows as $row) {
                $receipt = Receipt::fromRow($row);
                $after = $receipt->sequence;
                yield $receipt;
            }
        } while (count($rows) === $batch);
    }
}
