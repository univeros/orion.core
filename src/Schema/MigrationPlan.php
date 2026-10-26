<?php

declare(strict_types=1);

namespace Orion\Schema;

use LogicException;
use Orion\IndexName;
use Orion\Support\Canonical;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_unique;
use function array_values;
use function sprintf;
use function strcmp;
use function usort;

/**
 * The classified diff of a desired schema against the live one (v1 §8.1), a pure function of its inputs.
 *
 * Classification is total and fails closed: a new field is additive, and every other difference (removal, type,
 * analysis, flags, vector shape, a live field of unknown type, any attribute added later) is breaking. Query-time
 * attributes (`boost`) are not index state, no engine reports them, and they are left out of the diff.
 *
 * What no engine reports, the org key and the per-engine native settings, is diffed against the schema as
 * registered, under paths no field can have ({@see ORG_KEY}, {@see NATIVE}); every difference there is breaking.
 */
final readonly class MigrationPlan
{
    /** The path of an org key change; field names cannot start with `_`. */
    public const string ORG_KEY = '_org_key';
    /** The path prefix of a native settings change: `_native.<engine>`. */
    public const string NATIVE = '_native';
    /** Read when a query is compiled, never stored in the index. */
    private const array QUERY_TIME_ATTRIBUTES = ['boost'];

    /**
     * @param list<Change> $changes ordered by path
     */
    private function __construct(public IndexName $index, public array $changes)
    {
    }

    /**
     * A missing live index plans every desired field as added. The org key and native settings are compared with
     * `$registered`, the schema as Orion last applied it: against none, an index that is live but unregistered fails
     * closed (whatever the desired schema declares is a breaking change), and an index that is neither is a create.
     */
    public static function between(IndexSchema $desired, ?LiveSchema $live, ?IndexSchema $registered = null): self
    {
        $changes = self::diff($live === null ? [] : $live->fields, $desired->fields, '');
        if ($live !== null || $registered !== null) {
            array_push($changes, ...self::settings($registered, $desired));
        }
        usort($changes, static fn(Change $a, Change $b): int => strcmp($a->path, $b->path));

        return new self($desired->index, $changes);
    }

    /**
     * @return array{index: string, changes: list<array{path: string, kind: string, classification: string, before: mixed, after: mixed}>}
     */
    public function toArray(): array
    {
        return ['index' => $this->index->value, 'changes' => array_map(static fn(Change $change): array => $change->toArray(), $this->changes)];
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return list<Change>
     */
    public function additive(): array
    {
        return $this->classified(Classification::Additive);
    }

    /**
     * @return list<Change>
     */
    public function breaking(): array
    {
        return $this->classified(Classification::Breaking);
    }

    public function requiresReindex(): bool
    {
        return $this->breaking() !== [];
    }

    /**
     * The live fields with this plan's additions applied, for an adapter's in-place `apply()`.
     *
     * @param list<Field> $live
     * @return list<Field>
     * @throws LogicException when the plan has breaking changes: those need a reindex, never an in-place apply
     */
    public function applyTo(array $live): array
    {
        if ($this->requiresReindex()) {
            throw new LogicException(sprintf('The plan for %s has breaking changes; they need a reindex, never an in-place apply.', $this->index->value));
        }
        foreach ($this->changes as $change) {
            if ($change->kind === ChangeKind::Added && $change->after instanceof Field) {
                $live = Field::addAt($live, $change->path, $change->after);
            }
        }

        return $live;
    }

    /**
     * @return list<Change>
     */
    private static function settings(?IndexSchema $registered, IndexSchema $desired): array
    {
        $changes = [];
        if ($registered?->orgKey !== $desired->orgKey) {
            $changes[] = Change::setting(self::ORG_KEY, $registered?->orgKey, $desired->orgKey);
        }
        $before = array_filter($registered === null ? [] : $registered->native);
        $after = array_filter($desired->native);
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $engine) {
            $was = array_key_exists($engine, $before) ? Canonical::sort($before[$engine]) : null;
            $is = array_key_exists($engine, $after) ? Canonical::sort($after[$engine]) : null;
            if ($was !== $is) {
                $changes[] = Change::setting(self::NATIVE . '.' . $engine, $before[$engine] ?? null, $after[$engine] ?? null);
            }
        }

        return $changes;
    }

    /**
     * @param list<Field> $live
     * @param list<Field> $desired
     * @return list<Change>
     */
    private static function diff(array $live, array $desired, string $prefix): array
    {
        $before = Field::byName($live);
        $after = Field::byName($desired);
        $changes = [];
        foreach ($after as $name => $field) {
            if (!isset($before[$name])) {
                $changes[] = Change::added($prefix . $name, $field);
            } else {
                array_push($changes, ...self::compare($before[$name], $field, $prefix . $name));
            }
        }
        foreach ($before as $name => $field) {
            if (!isset($after[$name])) {
                $changes[] = Change::removed($prefix . $name, $field);
            }
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private static function compare(Field $live, Field $desired, string $path): array
    {
        $differing = self::differingAttributes($live, $desired);
        if ($differing === []) {
            return [];
        }
        if ($differing === ['fields']) {
            return self::diff($live->fields, $desired->fields, $path . '.');
        }

        return [Change::changed($path, $live, $desired)];
    }

    /**
     * Sub-fields differ when any of them does; recursion then finds which, ignoring order and query-time attributes.
     *
     * @return list<string>
     */
    private static function differingAttributes(Field $live, Field $desired): array
    {
        $before = self::indexState($live);
        $after = self::indexState($desired);
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $differing = array_filter($keys, static fn(string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null));
        if (Field::mapToArray($live->fields) !== Field::mapToArray($desired->fields)) {
            $differing[] = 'fields';
        }

        return array_values($differing);
    }

    /**
     * @return array<string, mixed>
     */
    private static function indexState(Field $field): array
    {
        $attributes = $field->toArray();
        unset($attributes['fields']);
        foreach (self::QUERY_TIME_ATTRIBUTES as $attribute) {
            unset($attributes[$attribute]);
        }

        return $attributes;
    }

    /**
     * @return list<Change>
     */
    private function classified(Classification $classification): array
    {
        return array_values(array_filter($this->changes, static fn(Change $change): bool => $change->classification === $classification));
    }
}
