<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\Support\Assert;
use Orion\Support\Input;

final readonly class ExistsFilter extends Filter
{
    public const string TYPE = 'exists';

    public function __construct(public string $field)
    {
        Assert::fieldPath($field, 'A filter field');
    }

    /**
     * @return array{type: string, field: string}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'field' => $this->field];
    }

    public static function fromArray(mixed $data, string $path, int $depth = 1): self
    {
        return new self(Input::string(Input::object($data, $path, ['type', 'field'])['field'], $path . '.field'));
    }
}
