<?php

declare(strict_types=1);

namespace Orion\Tests\Query;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\Fusion;
use Orion\Query\Hit;
use Orion\Query\ReciprocalRankFusion;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_reverse;

final class ReciprocalRankFusionTest extends TestCase
{
    public function testItScoresBySummedReciprocalRank(): void
    {
        $fused = ReciprocalRankFusion::fuse([
            'lexical' => [self::hit('a', 12.0), self::hit('b', 9.0), self::hit('c', 3.0)],
            'vector' => [self::hit('c', 0.9), self::hit('a', 0.8), self::hit('d', 0.1)],
        ], k: 60);

        // a: 1/61 + 1/62, c: 1/63 + 1/61, b: 1/62, d: 1/63
        self::assertSame(['a', 'c', 'b', 'd'], self::ids($fused->hits));
        self::assertEqualsWithDelta(1 / 61 + 1 / 62, $fused->hits[0]->score, 1e-12);
        self::assertEqualsWithDelta(1 / 63, $fused->hits[3]->score, 1e-12);
    }

    public function testIdenticalInputsFuseIdentically(): void
    {
        $rankings = [
            'lexical' => [self::hit('a', 1.0), self::hit('b', 1.0), self::hit('c', 1.0)],
            'vector' => [self::hit('b', 1.0), self::hit('a', 1.0), self::hit('c', 1.0)],
        ];

        $first = ReciprocalRankFusion::fuse($rankings);
        $second = ReciprocalRankFusion::fuse($rankings);

        self::assertEquals($first, $second);
    }

    public function testTheOrderOfTheRankingsDoesNotMatter(): void
    {
        $lexical = [self::hit('a', 1.0), self::hit('b', 1.0), self::hit('c', 1.0)];
        $vector = [self::hit('c', 1.0), self::hit('b', 1.0), self::hit('d', 1.0)];

        $one = ReciprocalRankFusion::fuse(['lexical' => $lexical, 'vector' => $vector]);
        $other = ReciprocalRankFusion::fuse(['vector' => $vector, 'lexical' => $lexical]);

        self::assertEquals($one, $other);
    }

    public function testTiesBreakByIdSoTheOrderIsTotal(): void
    {
        // b and a each hold rank 1 in one list and nothing else: equal scores.
        $fused = ReciprocalRankFusion::fuse(['lexical' => [self::hit('b', 5.0)], 'vector' => [self::hit('a', 0.5)]]);

        self::assertSame(['a', 'b'], self::ids($fused->hits));
        self::assertSame($fused->hits[0]->score, $fused->hits[1]->score);
    }

    public function testADuplicateWithinARankingCountsAtItsBestRank(): void
    {
        $fused = ReciprocalRankFusion::fuse(['lexical' => [self::hit('a', 2.0), self::hit('a', 1.0), self::hit('b', 0.5)]], k: 60);

        self::assertSame(['a', 'b'], self::ids($fused->hits));
        self::assertEqualsWithDelta(1 / 61, $fused->hits[0]->score, 1e-12);
        self::assertEqualsWithDelta(1 / 62, $fused->hits[1]->score, 1e-12);
    }

    public function testTheTraceRecordsEveryContribution(): void
    {
        $fused = ReciprocalRankFusion::fuse([
            'lexical' => [self::hit('a', 12.0), self::hit('b', 9.0)],
            'vector' => [self::hit('b', 0.9)],
        ], k: 10);

        self::assertEquals(Fusion::reciprocalRank(k: 10), $fused->trace->fusion);
        $contributions = array_map(
            static fn($c): array => [$c->id, $c->source, $c->rank, $c->score],
            $fused->trace->contributions,
        );
        self::assertEquals([
            ['a', 'lexical', 1, 1 / 11],
            ['b', 'lexical', 2, 1 / 12],
            ['b', 'vector', 1, 1 / 11],
        ], $contributions);
    }

    public function testSourceAndHighlightsComeFromTheRankingsInSourceOrder(): void
    {
        $fused = ReciprocalRankFusion::fuse([
            'vector' => [new Hit('a', 0.9, ['title' => 'from vector'], [])],
            'lexical' => [new Hit('a', 12.0, ['title' => 'from lexical'], ['title' => ['<em>shoe</em>']])],
        ]);

        self::assertSame(['title' => 'from lexical'], $fused->hits[0]->source);
        self::assertSame(['title' => ['<em>shoe</em>']], $fused->hits[0]->highlights);
    }

    public function testEmptyRankingsFuseToNothing(): void
    {
        $fused = ReciprocalRankFusion::fuse(['lexical' => [], 'vector' => []]);

        self::assertSame([], $fused->hits);
        self::assertSame([], $fused->trace->contributions);
    }

    public function testKMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Fusion::reciprocalRank(k: 0);
    }

    public function testCoreRefusesWeightedFusion(): void
    {
        try {
            Fusion::weighted(lexical: 0.3, vector: 0.7)->fuse(['lexical' => [self::hit('a', 1.0)]]);
            self::fail('Weighted fusion was computed in core.');
        } catch (UnsupportedClause $exception) {
            self::assertSame(Capability::HybridFusion, $exception->capability);
        }
    }

    public function testFusionDispatchesToReciprocalRank(): void
    {
        $rankings = ['lexical' => [self::hit('a', 1.0), self::hit('b', 1.0)], 'vector' => array_reverse([self::hit('a', 1.0), self::hit('b', 1.0)])];

        self::assertEquals(ReciprocalRankFusion::fuse($rankings, k: 7), Fusion::reciprocalRank(k: 7)->fuse($rankings));
    }

    private static function hit(string $id, float $score): Hit
    {
        return new Hit($id, $score, ['id' => $id], []);
    }

    /**
     * @param list<Hit> $hits
     * @return list<string>
     */
    private static function ids(array $hits): array
    {
        return array_map(static fn(Hit $hit): string => $hit->id, $hits);
    }
}
