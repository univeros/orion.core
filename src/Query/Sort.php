<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function sprintf;

final readonly class Sort
{
    /** Sorts by relevance instead of a field. */
    public const string SCORE = '_score';

    public function __construct(public string $field, public Direction $direction = Direction::Asc)
    {
        if ($field !== self::SCORE) {
            Assert::fieldPath($field, 'A sort field');
        }
    }

    /**
     * @return array{field: string, direction: string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'direction' => $this->direction->value];
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['field'], ['direction']);
        $direction = isset($data['direction']) ? Input::string($data['direction'], $path . '.direction') : Direction::Asc->value;

        return new self(
            Input::string($data['field'], $path . '.field'),
            Direction::tryFrom($direction) ?? throw new InvalidArgumentException(sprintf('%s.direction %s is not asc or desc.', $path, Assert::show($direction))),
        );
    }
}
