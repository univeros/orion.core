<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\Support\Assert;
use Orion\Support\Input;

final readonly class TermFilter extends Filter
{
    public const string TYPE = 'term';

    public function __construct(public string $field, public string|int|float|bool $value)
    {
        Assert::fieldPath($field, 'A filter field');
        Assert::value($value, 'A filter value');
    }

    /**
     * @return array{type: string, field: string, value: string|int|float|bool}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'field' => $this->field, 'value' => $this->value];
    }

    public static function fromArray(mixed $data, string $path, int $depth = 1): self
    {
        $data = Input::object($data, $path, ['type', 'field', 'value']);

        return new self(Input::string($data['field'], $path . '.field'), Input::scalar($data['value'], $path . '.value'));
    }
}
