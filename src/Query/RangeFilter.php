<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_filter;
use function is_string;
use function sprintf;

/**
 * Bounds on a numeric or date field; dates are ISO-8601 strings, literals rather than engine date math. At least one
 * bound is required.
 */
final readonly class RangeFilter extends Filter
{
    public const string TYPE = 'range';
    private const array BOUNDS = ['gt', 'gte', 'lt', 'lte'];

    public function __construct(
        public string $field,
        public int|float|string|null $gt = null,
        public int|float|string|null $gte = null,
        public int|float|string|null $lt = null,
        public int|float|string|null $lte = null,
    ) {
        Assert::fieldPath($field, 'A filter field');
        foreach ([$gt, $gte, $lt, $lte] as $bound) {
            if ($bound !== null) {
                Assert::value($bound, 'A range bound');
            }
        }
        if ($gt === null && $gte === null && $lt === null && $lte === null) {
            throw new InvalidArgumentException(sprintf('The range filter on %s needs at least one bound.', Assert::show($field)));
        }
    }

    /**
     * @return array<string, int|float|string>
     */
    public function toArray(): array
    {
        $bounds = ['gt' => $this->gt, 'gte' => $this->gte, 'lt' => $this->lt, 'lte' => $this->lte];

        return ['type' => self::TYPE, 'field' => $this->field, ...array_filter($bounds, static fn($bound): bool => $bound !== null)];
    }

    public static function fromArray(mixed $data, string $path, int $depth = 1): self
    {
        $data = Input::object($data, $path, ['type', 'field'], self::BOUNDS);
        $bounds = [];
        foreach (self::BOUNDS as $bound) {
            $bounds[$bound] = isset($data[$bound]) ? self::bound($data[$bound], $path . '.' . $bound) : null;
        }
        $field = Input::string($data['field'], $path . '.field');
        if ($bounds === ['gt' => null, 'gte' => null, 'lt' => null, 'lte' => null]) {
            throw new InvalidArgumentException(sprintf('%s needs at least one bound.', $path));
        }

        return new self($field, ...$bounds);
    }

    private static function bound(mixed $value, string $path): int|float|string
    {
        return is_string($value) ? $value : Input::number($value, $path);
    }
}
