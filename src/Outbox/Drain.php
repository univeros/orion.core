<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;
use Orion\Lifecycle\AliasManager;
use Orion\Observability\Metrics;
use Orion\Storage\Counters;
use Orion\Storage\Storage;
use Orion\Support\Assert;

use function array_map;
use function count;
use function end;
use function max;

/**
 * Writes an index's outbox entries, in offset order and in batches, to the index its alias points at (v1 §10), and
 * keeps its cursor in `orion_counter` (`drain:<index>`). Each run stops at the head it saw when it started, so it
 * ends under any write rate and reports the lag instead.
 *
 * The cursor moves only after the batch is written, together with its dead letters: an engine dying mid-batch leaves
 * the cursor where it was, and the next run writes the batch again, which versions make harmless (v1 §8.3). The
 * alias is resolved after each batch is read, so a reindex's swap committed before the read is honoured. Run it
 * outside the host's transactions, so every read sees what has committed.
 */
final readonly class Drain
{
    public const int DEFAULT_BATCH = 500;
    private const string CURSOR = 'drain:';

    private Counters $counters;

    public function __construct(
        private Storage $storage,
        private Outbox $outbox,
        private AliasManager $aliases,
        private Delivery $delivery,
        private DeadLetters $deadLetters,
        private Metrics $metrics,
    ) {
        $this->counters = new Counters($storage);
    }

    public function run(IndexName $index, int $batch = self::DEFAULT_BATCH): DrainResult
    {
        Assert::positive($batch, 'A drain batch');
        $from = $this->counters->value(self::CURSOR . $index->value);
        $head = $this->outbox->head($index);
        $cursor = $from;
        $applied = $stale = $failed = 0;
        while ($cursor < $head && $this->aliases->current($index) !== null) {
            $entries = $this->outbox->after($index, $cursor, $batch);
            // Everything up to the head had committed before this read, so a short batch leaves nothing behind it.
            $to = count($entries) < $batch ? max($head, self::last($entries, $cursor)) : self::last($entries, $cursor);
            $delivered = $entries === [] ? new Delivered(0, 0, [], []) : $this->deliver($index, $entries);
            $written = self::last($entries, $cursor);
            $this->storage->transaction(function () use ($index, $delivered, $to, $written): void {
                foreach ($delivered->failures as $failure) {
                    $this->deadLetters->record($failure['entry'], $failure['error']);
                }
                $this->deadLetters->clear($index, $delivered->settled, $written);
                $this->counters->advance(self::CURSOR . $index->value, $to);
            });
            $cursor = $to;
            $applied += $delivered->applied;
            $stale += $delivered->stale;
            $failed += count($delivered->failures);
        }
        $result = new DrainResult($index, $from, $cursor, $head, $applied, $stale, $failed);
        $this->measure($result);

        return $result;
    }

    /**
     * Drains every alias Orion has built.
     *
     * @return list<DrainResult>
     */
    public function runAll(int $batch = self::DEFAULT_BATCH): array
    {
        return array_map(fn(IndexName $index): DrainResult => $this->run($index, $batch), $this->aliases->aliases());
    }

    /**
     * Where the index's drain stands, without draining.
     */
    public function status(IndexName $index): DrainResult
    {
        $cursor = $this->counters->value(self::CURSOR . $index->value);

        return new DrainResult($index, $cursor, $cursor, $this->outbox->head($index), 0, 0, 0);
    }

    /**
     * @param non-empty-list<Entry> $entries
     */
    private function deliver(IndexName $index, array $entries): Delivered
    {
        $current = $this->aliases->current($index) ?? throw new \LogicException('The alias disappeared while draining.');

        return $this->delivery->send($current->engine, $this->aliases->targetOf($current), $entries);
    }

    /**
     * @param list<Entry> $entries
     */
    private static function last(array $entries, int $cursor): int
    {
        $last = end($entries);

        return $last === false ? $cursor : $last->offset;
    }

    private function measure(DrainResult $result): void
    {
        $attributes = ['index' => $result->index->value];
        $this->metrics->counter('orion.outbox.applied', $result->applied, $attributes);
        $this->metrics->counter('orion.outbox.stale', $result->stale, $attributes);
        $this->metrics->counter('orion.outbox.failed', $result->failed, $attributes);
        $this->metrics->gauge('orion.outbox.lag', $result->lag, $attributes);
    }
}
