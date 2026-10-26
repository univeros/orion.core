<?php

declare(strict_types=1);

namespace Orion\Storage;

use Orion\Support\Row;
use Throwable;

/**
 * Named integers in `orion_counter`: sequences handed out in commit order ({@see next()}) and cursors that only move
 * forward ({@see advance()}).
 */
final readonly class Counters
{
    public function __construct(private Storage $storage)
    {
    }

    /**
     * The next value of a sequence, starting at 1. The counter row stays locked until the caller's transaction ends,
     * so values are committed in the order they are handed out.
     */
    public function next(string $name): int
    {
        $criteria = ['name' => $name];
        $next = $this->storage->increment(Schema::COUNTER, $criteria, 'value');
        if ($next !== null) {
            return $next;
        }
        try {
            $this->storage->transaction(fn() => $this->storage->insert(Schema::COUNTER, [...$criteria, 'value' => 1]));

            return 1;
        } catch (Throwable $raced) {
            // Another caller created the row first; once it commits, the increment takes the next value from it.
            return $this->storage->increment(Schema::COUNTER, $criteria, 'value') ?? throw $raced;
        }
    }

    /**
     * The current value, 0 when the counter was never used.
     */
    public function value(string $name): int
    {
        $row = $this->storage->findOne(Schema::COUNTER, ['name' => $name]);

        return $row === null ? 0 : Row::int($row['value'], Schema::COUNTER . '.value');
    }

    /**
     * Moves a cursor forward to `$to`; a cursor already at or past it is left alone, so a slower caller cannot move it
     * back.
     */
    public function advance(string $name, int $to): void
    {
        if ($this->storage->update(Schema::COUNTER, ['name' => $name, 'value' => Condition::lt($to)], ['value' => $to]) > 0) {
            return;
        }
        if ($this->storage->findOne(Schema::COUNTER, ['name' => $name]) !== null) {
            return;
        }
        try {
            $this->storage->transaction(fn() => $this->storage->insert(Schema::COUNTER, ['name' => $name, 'value' => $to]));
        } catch (Throwable $raced) {
            if ($this->storage->update(Schema::COUNTER, ['name' => $name, 'value' => Condition::lt($to)], ['value' => $to]) === 0 && $this->value($name) < $to) {
                throw $raced;
            }
        }
    }
}
