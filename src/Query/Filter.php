<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function sprintf;

/**
 * A non-scoring restriction on the hits. The factories are the public vocabulary. Values are literals, compiled into
 * structured filters, never interpolated into a query or filter string.
 */
abstract readonly class Filter
{
    /** How deep bool filters may nest in declarative input. */
    public const int MAX_DEPTH = 10;
    /** Children per bool clause list, so one filter cannot build an arbitrarily large request. */
    public const int MAX_CHILDREN = 64;

    public static function term(string $field, string|int|float|bool $value): TermFilter
    {
        return new TermFilter($field, $value);
    }

    /**
     * @param list<string|int|float|bool> $values
     */
    public static function terms(string $field, array $values): TermsFilter
    {
        return new TermsFilter($field, $values);
    }

    public static function range(
        string $field,
        int|float|string|null $gt = null,
        int|float|string|null $gte = null,
        int|float|string|null $lt = null,
        int|float|string|null $lte = null,
    ): RangeFilter {
        return new RangeFilter($field, $gt, $gte, $lt, $lte);
    }

    public static function exists(string $field): ExistsFilter
    {
        return new ExistsFilter($field);
    }

    /**
     * @param list<Filter> $must
     * @param list<Filter> $should
     * @param list<Filter> $mustNot
     */
    public static function bool(array $must = [], array $should = [], array $mustNot = []): BoolFilter
    {
        return new BoolFilter($must, $should, $mustNot);
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    public static function fromArray(mixed $data, string $path, int $depth = 1): self
    {
        $keys = ['field', 'value', 'values', 'gt', 'gte', 'lt', 'lte', 'must', 'should', 'must_not'];
        $type = Input::string(Input::object($data, $path, ['type'], $keys)['type'], $path . '.type');

        return match ($type) {
            TermFilter::TYPE => TermFilter::fromArray($data, $path),
            TermsFilter::TYPE => TermsFilter::fromArray($data, $path),
            RangeFilter::TYPE => RangeFilter::fromArray($data, $path),
            ExistsFilter::TYPE => ExistsFilter::fromArray($data, $path),
            BoolFilter::TYPE => BoolFilter::fromArray($data, $path, $depth),
            default => throw new InvalidArgumentException(sprintf('%s.type %s is not a filter type.', $path, Assert::show($type))),
        };
    }
}
