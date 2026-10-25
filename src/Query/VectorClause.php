<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Schema\Field;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_is_list;
use function count;
use function is_float;

/**
 * k-nearest-neighbour retrieval on a vector field, where the engine reports `Capability::Vectors`.
 */
final readonly class VectorClause
{
    /** Elasticsearch's ceiling, the highest of the engines. */
    public const int MAX_K = 10000;

    /**
     * @param list<float> $vector at most {@see Field::MAX_DIMS} finite components
     */
    public function __construct(public string $field, public array $vector, public int $k)
    {
        Assert::fieldPath($field, 'A vector field');
        Assert::atMost(Assert::positive(count($vector), 'A vector\'s dimension count'), Field::MAX_DIMS, 'A vector\'s dimension count');
        if (!array_is_list($vector)) {
            throw new InvalidArgumentException('A vector must be a list.');
        }
        foreach ($vector as $component) {
            if (!is_float($component)) {
                throw new InvalidArgumentException('A vector\'s components must be floats.');
            }
            Assert::finite($component, 'A vector component');
        }
        Assert::atMost(Assert::positive($k, 'k'), self::MAX_K, 'k');
    }

    /**
     * @return array{field: string, vector: list<float>, k: int}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'vector' => $this->vector, 'k' => $this->k];
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['field', 'vector', 'k']);
        $vector = [];
        $components = Input::list($data['vector'], $path . '.vector');
        Assert::atMost(count($components), Field::MAX_DIMS, $path . '.vector\'s length');
        foreach ($components as $i => $component) {
            $vector[] = (float) Input::number($component, $path . '.vector[' . $i . ']');
        }

        return new self(Input::string($data['field'], $path . '.field'), $vector, Input::int($data['k'], $path . '.k'));
    }
}
