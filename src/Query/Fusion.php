<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Support\Assert;
use Orion\Support\Input;

use function sprintf;

/**
 * How lexical and vector rankings combine (v1 §5.1). Reciprocal rank is the portable default; weighted fusion
 * compares score magnitudes across retrievers, which only an engine's native hybrid fusion can do honestly.
 */
final readonly class Fusion
{
    public const int DEFAULT_K = 60;

    private function __construct(
        public FusionStrategy $strategy,
        public ?int $k,
        public ?float $lexical,
        public ?float $vector,
    ) {
    }

    public static function reciprocalRank(int $k = self::DEFAULT_K): self
    {
        return new self(FusionStrategy::ReciprocalRank, Assert::positive($k, 'The reciprocal rank constant k'), null, null);
    }

    public static function weighted(float $lexical, float $vector): self
    {
        Assert::finite($lexical, 'The lexical weight');
        Assert::finite($vector, 'The vector weight');
        if ($lexical < 0 || $vector < 0 || $lexical + $vector <= 0) {
            throw new InvalidArgumentException('Fusion weights must be non-negative and not both zero.');
        }

        return new self(FusionStrategy::Weighted, null, $lexical, $vector);
    }

    /**
     * Fuses rankings in core. Only reciprocal rank is computed here; weighted fusion is refused.
     *
     * @param array<string, list<Hit>> $rankings by source name, each best first
     */
    public function fuse(array $rankings): FusedRanking
    {
        if ($this->k === null) {
            throw UnsupportedClause::missing(Capability::HybridFusion, 'weighted fusion', 'core');
        }

        return ReciprocalRankFusion::fuse($rankings, $this->k);
    }

    /**
     * @return array{strategy: string, k: int}|array{strategy: string, lexical: float, vector: float}
     */
    public function toArray(): array
    {
        return $this->k !== null
            ? ['strategy' => $this->strategy->value, 'k' => $this->k]
            : ['strategy' => $this->strategy->value, 'lexical' => (float) $this->lexical, 'vector' => (float) $this->vector];
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['strategy'], ['k', 'lexical', 'vector']);
        $strategy = Input::string($data['strategy'], $path . '.strategy');

        return match (FusionStrategy::tryFrom($strategy)) {
            FusionStrategy::ReciprocalRank => self::reciprocalRankFrom(Input::object($data, $path, ['strategy'], ['k']), $path),
            FusionStrategy::Weighted => self::weightedFrom(Input::object($data, $path, ['strategy', 'lexical', 'vector']), $path),
            null => throw new InvalidArgumentException(sprintf('%s.strategy %s is not a fusion strategy.', $path, Assert::show($strategy))),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reciprocalRankFrom(array $data, string $path): self
    {
        return self::reciprocalRank(isset($data['k']) ? Input::int($data['k'], $path . '.k') : self::DEFAULT_K);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function weightedFrom(array $data, string $path): self
    {
        return self::weighted(
            (float) Input::number($data['lexical'], $path . '.lexical'),
            (float) Input::number($data['vector'], $path . '.vector'),
        );
    }
}
