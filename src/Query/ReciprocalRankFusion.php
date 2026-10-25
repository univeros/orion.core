<?php

declare(strict_types=1);

namespace Orion\Query;

use function array_keys;
use function array_map;
use function ksort;
use function strcmp;
use function usort;

use const SORT_STRING;

/**
 * Reciprocal rank fusion: a hit scores `Σ 1 / (k + rank)` over the rankings it appears in, rank 1-based. Identical
 * inputs fuse identically: rankings are summed in source-name order (float addition is not associative), a
 * duplicate within one ranking counts once at its best rank, and equal scores break by id, so the order is total.
 * A hit's source comes from the first ranking holding it, its highlights merged in the same order.
 */
final class ReciprocalRankFusion
{
    /**
     * @param array<string, list<Hit>> $rankings by source name, each best first
     */
    public static function fuse(array $rankings, int $k = Fusion::DEFAULT_K): FusedRanking
    {
        $fusion = Fusion::reciprocalRank($k);
        ksort($rankings, SORT_STRING);

        /** @var array<string, float> $scores */
        $scores = [];
        /** @var array<string, Hit> $first */
        $first = [];
        /** @var array<string, array<string, list<string>>> $highlights */
        $highlights = [];
        $contributions = [];
        foreach ($rankings as $source => $ranking) {
            $rank = 0;
            $seen = [];
            foreach ($ranking as $hit) {
                if (isset($seen[$hit->id])) {
                    continue;
                }
                $seen[$hit->id] = true;
                $score = 1.0 / ($k + ++$rank);
                $scores[$hit->id] = ($scores[$hit->id] ?? 0.0) + $score;
                $first[$hit->id] ??= $hit;
                $highlights[$hit->id] = ($highlights[$hit->id] ?? []) + $hit->highlights;
                $contributions[] = new Contribution($hit->id, (string) $source, $rank, $score);
            }
        }

        // Keys of numeric-looking ids turn into ints; the ids themselves are read back from the hits.
        $ids = array_map(static fn(int|string $key): string => $first[$key]->id, array_keys($first));
        usort($ids, static fn(string $a, string $b): int => ($scores[$b] <=> $scores[$a]) ?: strcmp($a, $b));

        $hits = array_map(
            static fn(string $id): Hit => new Hit($id, $scores[$id], $first[$id]->source, $highlights[$id]),
            $ids,
        );

        return new FusedRanking($hits, new FusionTrace($fusion, $contributions));
    }
}
