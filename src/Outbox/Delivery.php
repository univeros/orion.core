<?php

declare(strict_types=1);

namespace Orion\Outbox;

use Orion\IndexName;
use Orion\Registry\EngineRegistry;
use Orion\Write\WriteOutcome;
use Orion\Write\WriteStatus;

/**
 * Sends outbox entries to an engine index, version-stamped with their offsets, and accounts for every entry by its
 * document id: an entry the engine reports nothing for counts as failed, never as written.
 */
final readonly class Delivery
{
    /** How long an asynchronous engine may take to finish a bulk write. */
    public const float DEFAULT_TIMEOUT = 30.0;

    public function __construct(private EngineRegistry $engines, private float $timeout = self::DEFAULT_TIMEOUT)
    {
    }

    /**
     * @param list<Entry> $entries
     */
    public function send(string $engine, IndexName $target, array $entries): Delivered
    {
        $adapter = $this->engines->get($engine);
        $documents = [];
        $tombstones = [];
        foreach ($entries as $entry) {
            if ($entry->kind === EntryKind::Index) {
                $documents[] = $entry->toDocument();
            } else {
                $tombstones[] = $entry->toTombstone();
            }
        }
        $outcomes = [];
        if ($documents !== []) {
            $outcomes = self::byId($adapter->index($target, ...$documents)->await($this->timeout)->outcomes, $outcomes);
        }
        if ($tombstones !== []) {
            $outcomes = self::byId($adapter->delete($target, ...$tombstones)->await($this->timeout)->outcomes, $outcomes);
        }

        return self::account($entries, $outcomes);
    }

    /**
     * @param list<WriteOutcome> $reported
     * @param array<string, WriteOutcome> $outcomes
     * @return array<string, WriteOutcome>
     */
    private static function byId(array $reported, array $outcomes): array
    {
        foreach ($reported as $outcome) {
            $outcomes[$outcome->id] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param list<Entry> $entries
     * @param array<string, WriteOutcome> $outcomes
     */
    private static function account(array $entries, array $outcomes): Delivered
    {
        $applied = 0;
        $stale = 0;
        $settled = [];
        $failures = [];
        foreach ($entries as $entry) {
            $outcome = $outcomes[$entry->documentId] ?? null;
            if ($outcome === null || $outcome->status === WriteStatus::Failed) {
                $failures[] = ['entry' => $entry, 'error' => $outcome->error ?? 'The engine reported no outcome for this document.'];
                continue;
            }
            $settled[] = $entry->documentId;
            if ($outcome->status === WriteStatus::Applied) {
                ++$applied;
            } else {
                ++$stale;
            }
        }

        return new Delivered($applied, $stale, $settled, $failures);
    }
}
