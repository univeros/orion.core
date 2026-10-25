<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function sprintf;

/**
 * One scored lexical clause. A query's clauses are conjunctive: a hit matches all of them. The value is a literal:
 * adapters compile it into structured queries, never into a query string where it would be parsed as syntax.
 */
final readonly class Clause
{
    public const int MAX_VALUE_LENGTH = Assert::MAX_VALUE_LENGTH;

    private function __construct(public ClauseKind $kind, public string $field, public string|int|float|bool $value)
    {
        Assert::fieldPath($field, 'A clause field');
        Assert::value($value, 'A clause value');
    }

    public static function match(string $field, string $text): self
    {
        return new self(ClauseKind::Match, $field, Assert::notEmpty($text, 'A match text'));
    }

    public static function term(string $field, string|int|float|bool $value): self
    {
        return new self(ClauseKind::Term, $field, $value);
    }

    public static function prefix(string $field, string $prefix): self
    {
        return new self(ClauseKind::Prefix, $field, Assert::notEmpty($prefix, 'A prefix'));
    }

    public static function phrase(string $field, string $phrase): self
    {
        return new self(ClauseKind::Phrase, $field, Assert::notEmpty($phrase, 'A phrase'));
    }

    /**
     * @return array{type: string, field: string, value: string|int|float|bool}
     */
    public function toArray(): array
    {
        return ['type' => $this->kind->value, 'field' => $this->field, 'value' => $this->value];
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['type', 'field', 'value']);
        $type = Input::string($data['type'], $path . '.type');
        $field = Input::string($data['field'], $path . '.field');

        return match (ClauseKind::tryFrom($type)) {
            ClauseKind::Match => self::match($field, Input::string($data['value'], $path . '.value')),
            ClauseKind::Term => self::term($field, Input::scalar($data['value'], $path . '.value')),
            ClauseKind::Prefix => self::prefix($field, Input::string($data['value'], $path . '.value')),
            ClauseKind::Phrase => self::phrase($field, Input::string($data['value'], $path . '.value')),
            null => throw new InvalidArgumentException(sprintf('%s.type %s is not a clause type.', $path, Assert::show($type))),
        };
    }
}
