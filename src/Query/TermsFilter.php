<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_is_list;
use function count;

/**
 * The field equals any of the values.
 */
final readonly class TermsFilter extends Filter
{
    public const string TYPE = 'terms';
    public const int MAX_VALUES = 1000;

    /**
     * @param list<string|int|float|bool> $values
     */
    public function __construct(public string $field, public array $values)
    {
        Assert::fieldPath($field, 'A filter field');
        Assert::atMost(Assert::positive(count($values), 'A terms filter\'s value count'), self::MAX_VALUES, 'A terms filter\'s value count');
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('A terms filter\'s values must be a list.');
        }
        foreach ($values as $value) {
            Assert::value($value, 'A filter value');
        }
    }

    /**
     * @return array{type: string, field: string, values: list<string|int|float|bool>}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'field' => $this->field, 'values' => $this->values];
    }

    public static function fromArray(mixed $data, string $path, int $depth = 1): self
    {
        $data = Input::object($data, $path, ['type', 'field', 'values']);
        $values = [];
        $items = Input::list($data['values'], $path . '.values');
        Assert::atMost(count($items), self::MAX_VALUES, $path . '.values\' count');
        foreach ($items as $i => $value) {
            $values[] = Input::scalar($value, $path . '.values[' . $i . ']');
        }

        return new self(Input::string($data['field'], $path . '.field'), $values);
    }
}
