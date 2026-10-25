<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\Support\Assert;
use Orion\Support\Input;

/**
 * Value counts for a field, at most `size` buckets.
 */
final readonly class Facet
{
    public const int DEFAULT_SIZE = 10;
    public const int MAX_SIZE = 1000;

    public function __construct(public string $field, public int $size = self::DEFAULT_SIZE)
    {
        Assert::fieldPath($field, 'A facet field');
        Assert::atMost(Assert::positive($size, 'A facet size'), self::MAX_SIZE, 'A facet size');
    }

    /**
     * @return array{field: string, size: int}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'size' => $this->size];
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['field'], ['size']);

        return new self(
            Input::string($data['field'], $path . '.field'),
            isset($data['size']) ? Input::int($data['size'], $path . '.size') : self::DEFAULT_SIZE,
        );
    }
}
