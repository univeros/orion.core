<?php

declare(strict_types=1);

namespace Orion\Adapter\InMemory;

use Orion\Query\BoolFilter;
use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\ExistsFilter;
use Orion\Query\Filter;
use Orion\Query\RangeFilter;
use Orion\Query\TermFilter;
use Orion\Query\TermsFilter;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\VectorMetric;

use function array_filter;
use function array_intersect;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function count;
use function explode;
use function is_array;
use function is_bool;
use function is_numeric;
use function preg_split;
use function sqrt;
use function str_starts_with;
use function strtolower;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Evaluates clauses, filters and vector similarity against one document's source. Text analysis is ASCII
 * lowercasing and splitting on anything that is not a letter or digit: enough for development, not a stemmer.
 */
final readonly class Matcher
{
    /**
     * @param array<string, Field> $fields every declared field by dotted path
     */
    public function __construct(private array $fields)
    {
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * How well a clause matches: 0 when it does not.
     *
     * @param array<string, mixed> $source
     */
    public function score(Clause $clause, array $source): float
    {
        $field = $this->fields[$clause->field] ?? null;
        $values = $this->values($source, $clause->field);
        if ($field === null || $values === []) {
            return 0.0;
        }
        if ($field->type !== FieldType::Text) {
            return $this->anyEquals($values, $clause->value) ? 1.0 : 0.0;
        }
        $hits = 0;
        foreach ($values as $value) {
            $tokens = self::tokens((string) $value);
            $hits += match ($clause->kind) {
                ClauseKind::Match => count(array_intersect($tokens, self::tokens((string) $clause->value))),
                ClauseKind::Term => count(array_keys($tokens, (string) $clause->value, true)),
                ClauseKind::Prefix => count(array_filter($tokens, static fn(string $token): bool => str_starts_with($token, (string) $clause->value))),
                ClauseKind::Phrase => self::containsSequence($tokens, self::tokens((string) $clause->value)) ? 1 : 0,
            };
        }

        return $hits * $field->boost;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function passes(Filter $filter, array $source): bool
    {
        return match (true) {
            $filter instanceof TermFilter => $this->anyEquals($this->values($source, $filter->field), $filter->value),
            $filter instanceof TermsFilter => array_filter($filter->values, fn($value): bool => $this->anyEquals($this->values($source, $filter->field), $value)) !== [],
            $filter instanceof RangeFilter => array_filter($this->values($source, $filter->field), static fn($value): bool => self::inRange($value, $filter)) !== [],
            $filter instanceof ExistsFilter => $this->values($source, $filter->field) !== [],
            $filter instanceof BoolFilter => $this->passesBool($filter, $source),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $source
     * @param list<float> $vector
     */
    public function similarity(array $source, string $path, array $vector): ?float
    {
        $field = $this->fields[$path] ?? null;
        $stored = $source[$path] ?? null;
        if ($field === null || !is_array($stored) || count($stored) !== count($vector)) {
            return null;
        }
        $dot = $normA = $normB = $distance = 0.0;
        foreach ($vector as $i => $component) {
            $value = (float) $stored[$i];
            $dot += $component * $value;
            $normA += $component * $component;
            $normB += $value * $value;
            $distance += ($component - $value) ** 2;
        }

        return match ($field->metric) {
            VectorMetric::DotProduct => $dot,
            VectorMetric::Euclidean => 1.0 / (1.0 + sqrt($distance)),
            default => $normA > 0 && $normB > 0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0,
        };
    }

    /**
     * Every value at a dotted path; lists are multi-valued fields, a vector is one value.
     *
     * @param array<array-key, mixed> $source
     * @return list<mixed>
     */
    public function values(array $source, string $path): array
    {
        $field = $this->fields[$path] ?? null;
        $parts = explode('.', $path, 2);
        $head = $parts[0];
        $rest = $parts[1] ?? null;
        if (!array_key_exists($head, $source) || $source[$head] === null) {
            return [];
        }
        $value = $source[$head];
        if ($rest !== null) {
            $children = is_array($value) && array_is_list($value) ? $value : [$value];
            $values = [];
            foreach ($children as $child) {
                if (is_array($child)) {
                    $values = [...$values, ...$this->values($child, $rest)];
                }
            }

            return $values;
        }
        if ($field?->type === FieldType::Vector || !is_array($value) || !array_is_list($value)) {
            return [$value];
        }

        return $value;
    }

    /**
     * Equality as engines see it: booleans as booleans, numbers numerically, everything else as strings.
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return !is_array($a) && !is_array($b) && (string) $a === (string) $b;
    }

    /**
     * Numbers numerically, everything else (ISO dates included) as strings.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        return (string) $a <=> (string) $b;
    }

    /**
     * @param list<mixed> $values
     */
    private function anyEquals(array $values, mixed $expected): bool
    {
        foreach ($values as $value) {
            if (self::equals($value, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function passesBool(BoolFilter $filter, array $source): bool
    {
        foreach ($filter->must as $must) {
            if (!$this->passes($must, $source)) {
                return false;
            }
        }
        foreach ($filter->mustNot as $mustNot) {
            if ($this->passes($mustNot, $source)) {
                return false;
            }
        }
        if ($filter->should === []) {
            return true;
        }
        foreach ($filter->should as $should) {
            if ($this->passes($should, $source)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(mixed $value, RangeFilter $range): bool
    {
        return ($range->gt === null || self::compare($value, $range->gt) > 0)
            && ($range->gte === null || self::compare($value, $range->gte) >= 0)
            && ($range->lt === null || self::compare($value, $range->lt) < 0)
            && ($range->lte === null || self::compare($value, $range->lte) <= 0);
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $sequence
     */
    private static function containsSequence(array $tokens, array $sequence): bool
    {
        $length = count($sequence);
        for ($i = 0; $length > 0 && $i + $length <= count($tokens); ++$i) {
            if (array_slice($tokens, $i, $length) === $sequence) {
                return true;
            }
        }

        return false;
    }
}
