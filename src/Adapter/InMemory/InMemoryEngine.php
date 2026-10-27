<?php

declare(strict_types=1);

namespace Orion\Adapter\InMemory;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
use Orion\Adapter\QueryCompiling;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\UnsupportedClause;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Support\Assert;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use Orion\Write\WriteOutcome;
use Override;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_slice;
use function count;
use function hrtime;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function ksort;
use function preg_match;
use function sprintf;
use function strcmp;
use function usort;

use const SORT_STRING;

/**
 * The development engine (docs/spec/revision.md §1): every adapter verb over PHP arrays, passing the same
 * conformance suite as the real engines. `raw()` takes `callable(array $source): bool`. No native fusion, nested
 * documents, aliases or cursors; version-stamped writes and deletes, with tombstones kept forever.
 */
final class InMemoryEngine implements SearchAdapter, SchemaAdapter, WriteAdapter, QueryCompiling
{
    private const string NAME = 'in-memory';
    private const array CAPABILITIES = [Capability::Vectors, Capability::Facets, Capability::Highlighting, Capability::OptimisticConcurrency];

    /** @var array<string, array{fields: list<Field>, docs: array<string, array{source: array<string, mixed>, version: int}>, tombstones: array<string, int>}> */
    private array $indices = [];

    #[Override]
    public function supports(Capability $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    /**
     * The in-memory engine has no query language: it runs the neutral query itself, so that is its native form.
     */
    #[Override]
    public function compile(IndexName $index, Query $query): array
    {
        return $query->toArray();
    }

    #[Override]
    public function search(IndexName $index, Query $query): ResultSet
    {
        $started = hrtime(true);
        if ($query->vector !== null && $query->clauses !== []) {
            throw UnsupportedClause::missing(Capability::HybridFusion, 'hybrid retrieval', self::NAME);
        }
        $matcher = $this->matcher($index);
        $shaper = new Shaper($matcher);
        $matches = $query->vector !== null
            ? $this->nearest($index, $matcher, $query)
            : $shaper->order($this->lexical($index, $matcher, $query), $query->sorts);
        $page = array_slice($matches, ($query->page->page - 1) * $query->page->size, $query->page->size);
        $hits = array_map(
            static fn(array $match): Hit => new Hit($match['id'], $match['score'], $matcher->withoutVectors($match['source']), $shaper->highlights($match['source'], $query->highlights, $query->clauses)),
            $page,
        );

        return new ResultSet($hits, count($matches), false, $shaper->facets($matches, $query->facets), (hrtime(true) - $started) / 1e6);
    }

    #[Override]
    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        $started = hrtime(true);
        $matcher = $this->matcher($index);
        $hits = [];
        foreach ($this->documents($index) as $id => $document) {
            if ($this->passesAll($matcher, $scope, $document['source']) && $native($document['source']) === true) {
                $hits[] = new Hit((string) $id, 1.0, $matcher->withoutVectors($document['source']), []);
            }
        }

        return new ResultSet($hits, count($hits), false, [], (hrtime(true) - $started) / 1e6);
    }

    #[Override]
    public function ensure(IndexSchema $schema): void
    {
        if (isset($this->indices[$schema->index->value])) {
            return;
        }
        foreach (self::flatten($schema->fields) as $field) {
            if ($field->type === FieldType::Nested) {
                throw UnsupportedClause::missing(Capability::NestedDocuments, 'nested field ' . Assert::show($field->name), self::NAME);
            }
        }
        $this->indices[$schema->index->value] = ['fields' => $schema->fields, 'docs' => [], 'tombstones' => []];
    }

    #[Override]
    public function inspect(IndexName $index): ?LiveSchema
    {
        return isset($this->indices[$index->value]) ? new LiveSchema($index, $this->indices[$index->value]['fields']) : null;
    }

    #[Override]
    public function plan(IndexSchema $desired): MigrationPlan
    {
        return MigrationPlan::between($desired, $this->inspect($desired->index));
    }

    #[Override]
    public function apply(MigrationPlan $plan): PendingTask
    {
        $this->indices[$plan->index->value]['fields'] = $plan->applyTo($this->stored($plan->index)['fields']);

        return PendingTask::completed();
    }

    #[Override]
    public function drop(IndexName $index): void
    {
        unset($this->indices[$index->value]);
    }

    #[Override]
    public function alias(IndexName $alias, IndexName $physical): void
    {
        throw UnsupportedClause::missing(Capability::NativeAliases, 'alias ' . Assert::show($alias->value), self::NAME);
    }

    #[Override]
    public function resolve(IndexName $alias): ?IndexName
    {
        throw UnsupportedClause::missing(Capability::NativeAliases, 'alias ' . Assert::show($alias->value), self::NAME);
    }

    #[Override]
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $declared = $this->stored($index)['fields'];
        $outcomes = [];
        foreach ($docs as $doc) {
            $invalid = self::invalid($doc->fields, $declared);
            if ($invalid !== null) {
                $outcomes[] = WriteOutcome::failed($doc->id, $invalid);
            } elseif ($this->isStale($index, $doc->id, $doc->version)) {
                $outcomes[] = WriteOutcome::stale($doc->id);
            } else {
                unset($this->indices[$index->value]['tombstones'][$doc->id]);
                $this->indices[$index->value]['docs'][$doc->id] = ['source' => $doc->fields, 'version' => $doc->version];
                $outcomes[] = WriteOutcome::applied($doc->id);
            }
        }

        return BulkResult::completed(...$outcomes);
    }

    #[Override]
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        $this->stored($index);
        $outcomes = [];
        foreach ($tombstones as $tombstone) {
            if ($this->isStale($index, $tombstone->id, $tombstone->version)) {
                $outcomes[] = WriteOutcome::stale($tombstone->id);
                continue;
            }
            unset($this->indices[$index->value]['docs'][$tombstone->id]);
            $this->indices[$index->value]['tombstones'][$tombstone->id] = $tombstone->version;
            $outcomes[] = WriteOutcome::applied($tombstone->id);
        }

        return BulkResult::completed(...$outcomes);
    }

    #[Override]
    public function flush(IndexName $index, bool $await = false): void
    {
    }

    /**
     * @return list<array{id: string, score: float, source: array<string, mixed>}>
     */
    private function lexical(IndexName $index, Matcher $matcher, Query $query): array
    {
        $matches = [];
        foreach ($this->documents($index) as $id => $document) {
            if (!$this->passesAll($matcher, $query->filters, $document['source'])) {
                continue;
            }
            $score = $query->clauses === [] ? 1.0 : 0.0;
            foreach ($query->clauses as $clause) {
                $clauseScore = $matcher->score($clause, $document['source']);
                if ($clauseScore <= 0.0) {
                    continue 2;
                }
                $score += $clauseScore;
            }
            $matches[] = ['id' => (string) $id, 'score' => $score, 'source' => $document['source']];
        }

        return $matches;
    }

    /**
     * Exact k-nearest neighbours among the documents passing the filters, best first, ties by id.
     *
     * @return list<array{id: string, score: float, source: array<string, mixed>}>
     */
    private function nearest(IndexName $index, Matcher $matcher, Query $query): array
    {
        $vector = $query->vector ?? throw new LogicException('A vector query has a vector clause.');
        $matches = [];
        foreach ($this->documents($index) as $id => $document) {
            $similarity = $matcher->similarity($document['source'], $vector->field, $vector->vector);
            if ($similarity !== null && $this->passesAll($matcher, $query->filters, $document['source'])) {
                $matches[] = ['id' => (string) $id, 'score' => $similarity, 'source' => $document['source']];
            }
        }
        usort($matches, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['id'], $b['id']));

        return array_slice($matches, 0, $vector->k);
    }

    /**
     * @param array<Filter> $filters
     * @param array<string, mixed> $source
     */
    private function passesAll(Matcher $matcher, array $filters, array $source): bool
    {
        foreach ($filters as $filter) {
            if (!$matcher->passes($filter, $source)) {
                return false;
            }
        }

        return true;
    }

    private function isStale(IndexName $index, string $id, int $version): bool
    {
        $stored = $this->indices[$index->value];
        $current = $stored['docs'][$id]['version'] ?? $stored['tombstones'][$id] ?? null;

        return $current !== null && $version <= $current;
    }

    private function matcher(IndexName $index): Matcher
    {
        return new Matcher(self::flatten($this->stored($index)['fields']));
    }

    /**
     * @return array<string, array{source: array<string, mixed>, version: int}> by id, in id order
     */
    private function documents(IndexName $index): array
    {
        $docs = $this->stored($index)['docs'];
        ksort($docs, SORT_STRING);

        return $docs;
    }

    /**
     * @return array{fields: list<Field>, docs: array<string, array{source: array<string, mixed>, version: int}>, tombstones: array<string, int>}
     */
    private function stored(IndexName $index): array
    {
        return $this->indices[$index->value] ?? throw new InvalidArgumentException(sprintf('The index %s does not exist; ensure it first.', $index->value));
    }

    /**
     * @param list<Field> $fields
     * @return array<string, Field> by dotted path
     */
    private static function flatten(array $fields, string $prefix = ''): array
    {
        $flat = [];
        foreach ($fields as $field) {
            $flat[$prefix . $field->name] = $field;
            $flat = [...$flat, ...self::flatten($field->fields, $prefix . $field->name . '.')];
        }

        return $flat;
    }

    /**
     * Why a document cannot be indexed, or null: a key the fields do not declare (looking into objects), or a value
     * the field's type rejects, as a strictly mapped engine would.
     *
     * @param array<array-key, mixed> $source
     * @param list<Field> $fields
     */
    private static function invalid(array $source, array $fields, string $prefix = ''): ?string
    {
        $byName = Field::byName($fields);
        foreach ($source as $key => $value) {
            $path = $prefix . $key;
            $field = $byName[(string) $key] ?? null;
            if ($field === null) {
                return sprintf('%s is not a declared field.', Assert::show($path));
            }
            if ($value === null) {
                continue;
            }
            if ($field->fields !== []) {
                foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $child) {
                    $reason = is_array($child) ? self::invalid($child, $field->fields, $path . '.') : sprintf('%s must be an object.', Assert::show($path));
                    if ($reason !== null) {
                        return $reason;
                    }
                }
                continue;
            }
            if (!self::accepts($field, $value)) {
                return sprintf('%s does not accept that value for a %s field.', Assert::show($path), $field->type->value);
            }
        }

        return null;
    }

    private static function accepts(Field $field, mixed $value): bool
    {
        if ($field->type === FieldType::Vector) {
            return is_array($value) && array_is_list($value) && count($value) === $field->dims
                && array_filter($value, static fn($component): bool => !is_int($component) && !is_float($component)) === [];
        }
        foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
            $ok = match ($field->type) {
                FieldType::Integer => is_int($item) || (is_string($item) && preg_match('/^-?\d+\z/', $item) === 1),
                FieldType::Float => is_int($item) || is_float($item) || (is_string($item) && is_numeric($item)),
                FieldType::Boolean => is_bool($item) || $item === 'true' || $item === 'false',
                default => is_scalar($item) || is_array($item),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
