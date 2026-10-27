<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;

use function abs;
use function array_map;
use function checkdate;
use function count;
use function floor;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Binds a query to the schema the index was registered with (v1 §5, §6): every field it names must be declared and
 * typed for the use (text for match, phrase, prefix and highlight; `filterable`, `sortable`, `facetable` as flagged;
 * a vector of the declared dimensions), every value must be of the field's type, and each clause takes the
 * schema's query-time boost. One check for every engine, before anything is compiled, so a mistyped query is refused
 * with its path instead of answered wrongly or differently per engine. Pure: it executes nothing.
 */
final class Binder
{
    private const string ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?)?(?:Z|[+-]\d{2}:?\d{2})?\z/';
    /** What every engine stores an integer in. */
    private const float MAX_INTEGER = 9223372036854775807.0;
    private const array SCORED = [FieldType::Text, FieldType::Keyword];
    private const array EXACT = [FieldType::Keyword, FieldType::Integer, FieldType::Float, FieldType::Boolean, FieldType::Date];
    private const array RANGED = [FieldType::Keyword, FieldType::Integer, FieldType::Float, FieldType::Date];

    /**
     * @throws InvalidArgumentException naming what does not fit
     */
    public static function bind(Query $query, IndexSchema $schema): Query
    {
        $binder = new self($schema);
        $clauses = array_map($binder->clause(...), $query->clauses);
        if ($query->vector !== null) {
            $binder->vector($query->vector);
        }
        foreach ($query->filters as $filter) {
            $binder->filter($filter);
        }
        foreach ($query->sorts as $sort) {
            if ($sort->field !== Sort::SCORE && !$binder->field($sort->field, 'sort')->sortable) {
                throw $binder->refuse($sort->field, 'is not sortable');
            }
        }
        foreach ($query->facets as $facet) {
            if (!$binder->field($facet->field, 'facet')->facetable) {
                throw $binder->refuse($facet->field, 'is not facetable');
            }
        }
        foreach ($query->highlights as $field) {
            if ($binder->field($field, 'highlight')->type !== FieldType::Text) {
                throw $binder->refuse($field, 'is not a text field, so it cannot be highlighted');
            }
        }

        return $clauses === $query->clauses ? $query : $query->withClauses($clauses);
    }

    private function __construct(private readonly IndexSchema $schema)
    {
    }

    private function clause(Clause $clause): Clause
    {
        $factors = [];
        foreach ($clause->fieldNames() as $name) {
            $field = $this->field($name, $clause->kind->value);
            // A term is exact: on analysed text it would match one engine's token and not another's.
            $allowed = $clause->kind === ClauseKind::Term ? self::EXACT : self::SCORED;
            if (!in_array($field->type, $allowed, true)) {
                throw $this->refuse($name, sprintf('has type %s, which a %s cannot read', $field->type->value, $clause->kind->value));
            }
            if ($clause->kind === ClauseKind::Term) {
                $this->typed($clause->value, $field, $name);
            }
            $factors[$name] = $field->boost;
        }

        return $clause->weighted($factors);
    }

    private function vector(VectorClause $vector): void
    {
        $field = $this->field($vector->field, 'vector');
        if ($field->type !== FieldType::Vector) {
            throw $this->refuse($vector->field, 'is not a vector field');
        }
        if ($field->dims !== count($vector->vector)) {
            throw $this->refuse($vector->field, sprintf('has %d dimensions, the query vector %d', (int) $field->dims, count($vector->vector)));
        }
    }

    private function filter(Filter $filter): void
    {
        if ($filter instanceof BoolFilter) {
            foreach ([...$filter->must, ...$filter->should, ...$filter->mustNot] as $child) {
                $this->filter($child);
            }

            return;
        }
        if ($filter instanceof ExistsFilter) {
            $this->field($filter->field, 'filter');

            return;
        }
        [$name, $values, $types, $kind] = match (true) {
            $filter instanceof TermFilter => [$filter->field, [$filter->value], self::EXACT, TermFilter::TYPE],
            $filter instanceof TermsFilter => [$filter->field, $filter->values, self::EXACT, TermsFilter::TYPE],
            $filter instanceof RangeFilter => [$filter->field, [$filter->gt, $filter->gte, $filter->lt, $filter->lte], self::RANGED, RangeFilter::TYPE],
            default => throw new InvalidArgumentException('Unknown filter ' . $filter::class),
        };
        $field = $this->field($name, 'filter');
        if (!$field->filterable || !in_array($field->type, $types, true)) {
            throw $this->refuse($name, 'is not filterable' . ($field->filterable ? sprintf(' by a %s filter', $kind) : ''));
        }
        foreach ($values as $value) {
            if ($value !== null) {
                $this->typed($value, $field, $name);
            }
        }
    }

    private function typed(string|int|float|bool $value, Field $field, string $name): void
    {
        $fits = match ($field->type) {
            FieldType::Integer => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) <= self::MAX_INTEGER),
            FieldType::Float => is_int($value) || is_float($value),
            FieldType::Boolean => is_bool($value),
            FieldType::Date => is_string($value) && self::isDate($value),
            default => !is_bool($value),
        };
        if (!$fits) {
            throw $this->refuse($name, sprintf('has type %s; %s is not a %s', $field->type->value, Assert::show(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value), match ($field->type) {
                FieldType::Integer => 'whole number',
                FieldType::Float => 'number',
                FieldType::Boolean => 'boolean',
                FieldType::Date => 'date in ISO 8601',
                default => 'string',
            }));
        }
    }

    /**
     * An ISO 8601 date, calendar-valid: engines parse it as an instant and refuse what is not one.
     */
    private static function isDate(string $value): bool
    {
        if (preg_match(self::ISO_DATE, $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return ((int) ($m[4] ?? 0)) < 24 && ((int) ($m[5] ?? 0)) < 60 && ((int) ($m[6] ?? 0)) < 60;
    }

    private function field(string $path, string $use): Field
    {
        return $this->schema->fieldAt($path)
            ?? throw new InvalidArgumentException(sprintf('The query\'s %s names %s, which %s does not declare.', $use, Assert::show($path), Assert::show($this->schema->index->value)));
    }

    private function refuse(string $path, string $why): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The field %s of %s %s.', Assert::show($path), Assert::show($this->schema->index->value), $why));
    }
}
