<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_map;

/**
 * Composition: every `must`, at least one `should` when any is given, no `mustNot`.
 */
final readonly class BoolFilter extends Filter
{
    public const string TYPE = 'bool';

    /**
     * @param list<Filter> $must
     * @param list<Filter> $should
     * @param list<Filter> $mustNot
     */
    public function __construct(public array $must = [], public array $should = [], public array $mustNot = [])
    {
        if ($must === [] && $should === [] && $mustNot === []) {
            throw new InvalidArgumentException('A bool filter needs at least one clause.');
        }
    }

    /**
     * @return array{type: string, must: list<array<string, mixed>>, should: list<array<string, mixed>>, must_not: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $toArray = static fn(Filter $filter): array => $filter->toArray();

        return [
            'type' => self::TYPE,
            'must' => array_map($toArray, $this->must),
            'should' => array_map($toArray, $this->should),
            'must_not' => array_map($toArray, $this->mustNot),
        ];
    }

    public static function fromArray(mixed $data, string $path, int $depth = 1): self
    {
        Assert::atMost($depth, Filter::MAX_DEPTH, $path . '\'s bool nesting depth');
        $data = Input::object($data, $path, ['type'], ['must', 'should', 'must_not']);

        return new self(
            self::filters($data['must'] ?? [], $path . '.must', $depth),
            self::filters($data['should'] ?? [], $path . '.should', $depth),
            self::filters($data['must_not'] ?? [], $path . '.must_not', $depth),
        );
    }

    /**
     * @return list<Filter>
     */
    private static function filters(mixed $data, string $path, int $depth): array
    {
        $filters = [];
        foreach (Input::list($data, $path) as $i => $filter) {
            $filters[] = Filter::fromArray($filter, $path . '[' . $i . ']', $depth + 1);
        }

        return $filters;
    }
}
