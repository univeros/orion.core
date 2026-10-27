<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_is_list;
use function array_key_first;
use function array_keys;
use function count;
use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * One scored lexical clause. A query's clauses are conjunctive: a hit matches all of them. The value is a literal:
 * adapters compile it into structured queries, never into a query string where it would be parsed as syntax. A
 * `match` may read several fields, each with a weight; the other kinds read one. Weights are query-time boosts:
 * the searcher multiplies in the registered schema's, so adapters compile what they are given.
 */
final readonly class Clause
{
    public const int MAX_VALUE_LENGTH = Assert::MAX_VALUE_LENGTH;
    public const float DEFAULT_WEIGHT = 1.0;

    /** The first field, for the kinds that read one. */
    public string $field;

    /**
     * @param array<string, float> $fields weight by field, in the order given
     */
    private function __construct(public ClauseKind $kind, public array $fields, public string|int|float|bool $value)
    {
        if ($fields === []) {
            throw new InvalidArgumentException('A clause needs a field.');
        }
        if (count($fields) > 1 && $kind !== ClauseKind::Match) {
            throw new InvalidArgumentException(sprintf('Only a match reads several fields; a %s reads one.', $kind->value));
        }
        foreach ($fields as $field => $weight) {
            Assert::fieldPath((string) $field, 'A clause field');
            if (Assert::finite($weight, 'A clause weight') <= 0) {
                throw new InvalidArgumentException(sprintf('The weight of %s must be positive.', Assert::show($field)));
            }
        }
        Assert::value($value, 'A clause value');
        $this->field = (string) array_key_first($fields);
    }

    /**
     * @param string|list<string>|array<string, float> $fields one field, several at weight 1, or weights by field
     */
    public static function match(string|array $fields, string $text): self
    {
        return new self(ClauseKind::Match, self::weights($fields), Assert::notEmpty($text, 'A match text'));
    }

    public static function term(string $field, string|int|float|bool $value): self
    {
        return new self(ClauseKind::Term, [$field => self::DEFAULT_WEIGHT], $value);
    }

    public static function prefix(string $field, string $prefix): self
    {
        return new self(ClauseKind::Prefix, [$field => self::DEFAULT_WEIGHT], Assert::notEmpty($prefix, 'A prefix'));
    }

    public static function phrase(string $field, string $phrase): self
    {
        return new self(ClauseKind::Phrase, [$field => self::DEFAULT_WEIGHT], Assert::notEmpty($phrase, 'A phrase'));
    }

    /**
     * A copy with each field's weight multiplied by the given factor (1 where none is given).
     *
     * @param array<string, float> $factors by field
     */
    public function weighted(array $factors): self
    {
        $fields = [];
        foreach ($this->fields as $field => $weight) {
            $fields[$field] = $weight * ($factors[$field] ?? self::DEFAULT_WEIGHT);
        }

        return new self($this->kind, $fields, $this->value);
    }

    /**
     * The field names, for the kinds and compilers that do not weigh.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (count($this->fields) > 1) {
            return ['type' => $this->kind->value, 'fields' => $this->fields, 'value' => $this->value];
        }
        $weight = $this->fields[$this->field];

        return ['type' => $this->kind->value, 'field' => $this->field, 'value' => $this->value, ...($weight === self::DEFAULT_WEIGHT ? [] : ['boost' => $weight])];
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['type', 'value'], ['field', 'fields', 'boost']);
        $type = Input::string($data['type'], $path . '.type');
        $kind = ClauseKind::tryFrom($type) ?? throw new InvalidArgumentException(sprintf('%s.type %s is not a clause type.', $path, Assert::show($type)));
        $fields = self::fieldsFrom($data, $path);
        if ($kind === ClauseKind::Match) {
            return self::match($fields, Input::string($data['value'], $path . '.value'));
        }
        $field = (string) array_key_first($fields);
        $clause = match ($kind) {
            ClauseKind::Term => self::term($field, Input::scalar($data['value'], $path . '.value')),
            ClauseKind::Prefix => self::prefix($field, Input::string($data['value'], $path . '.value')),
            ClauseKind::Phrase => self::phrase($field, Input::string($data['value'], $path . '.value')),
        };
        if (count($fields) > 1) {
            throw new InvalidArgumentException(sprintf('%s.fields: only a match reads several fields; a %s reads one.', $path, $type));
        }

        return $clause->weighted($fields);
    }

    /**
     * `field` with an optional `boost`, or `fields` as a list of names or weights by name.
     *
     * @param array<string, mixed> $data
     * @return array<string, float>
     */
    private static function fieldsFrom(array $data, string $path): array
    {
        if (isset($data['field']) === isset($data['fields'])) {
            throw new InvalidArgumentException(sprintf('%s needs field or fields, not both.', $path));
        }
        if (isset($data['field'])) {
            $weight = isset($data['boost']) ? (float) Input::number($data['boost'], $path . '.boost') : self::DEFAULT_WEIGHT;

            return [Input::string($data['field'], $path . '.field') => $weight];
        }
        if (isset($data['boost'])) {
            throw new InvalidArgumentException(sprintf('%s.boost goes with field; fields carry their own weights.', $path));
        }
        $fields = [];
        $given = $data['fields'];
        if (!is_array($given)) {
            throw new InvalidArgumentException(sprintf('%s.fields must be a list of field names or weights by field name, %s given.', $path, get_debug_type($given)));
        }
        if (array_is_list($given)) {
            foreach ($given as $i => $field) {
                $fields[Input::string($field, $path . '.fields[' . $i . ']')] = self::DEFAULT_WEIGHT;
            }
        } else {
            foreach ($given as $field => $weight) {
                $fields[Assert::fieldPath((string) $field, 'In ' . $path . '.fields, the field')] = (float) Input::number($weight, $path . '.fields.' . $field);
            }
        }
        if ($fields === []) {
            throw new InvalidArgumentException(sprintf('%s.fields needs at least one field.', $path));
        }

        return $fields;
    }

    /**
     * @param string|list<string>|array<string, float> $fields
     * @return array<string, float>
     */
    private static function weights(string|array $fields): array
    {
        if (is_string($fields)) {
            return [$fields => self::DEFAULT_WEIGHT];
        }
        if ($fields === []) {
            throw new InvalidArgumentException('A match needs at least one field.');
        }
        if (array_is_list($fields)) {
            $weights = [];
            foreach ($fields as $field) {
                if (!is_string($field)) {
                    throw new InvalidArgumentException('A match lists its fields by name.');
                }
                $weights[$field] = self::DEFAULT_WEIGHT;
            }

            return $weights;
        }
        $weights = [];
        foreach ($fields as $field => $weight) {
            if (is_array($weight)) {
                throw new InvalidArgumentException('A match weighs its fields with numbers.');
            }
            $weights[(string) $field] = (float) $weight;
        }

        return $weights;
    }
}
