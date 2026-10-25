<?php

declare(strict_types=1);

namespace Orion\Adapter\InMemory;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\UnsupportedClause;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\ChangeKind;
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

use function array_is_list;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function hrtime;
use function in_array;
use function is_array;
use function ksort;
use function sprintf;
use function strcmp;
use function usort;

use const SORT_STRING;

/**
 * The development engine (docs/spec/revision.md §1): every adapter verb over PHP arrays, passing the same
 * conformance suite as the real engines. `raw()` takes `callable(array $source): bool`. No native fusion, nested
 * documents, aliases or cursors; version-stamped writes and deletes, with tombstones kept forever.
 */
final class InMemoryEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
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
            static fn(array $match): Hit => new Hit($match['id'], $match['score'], $match['source'], $shaper->highlights($match['source'], $query->highlights, $query->clauses)),
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
                $hits[] = new Hit((string) $id, 1.0, $document['source'], []);
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
        if ($plan->requiresReindex()) {
            throw new LogicException(sprintf('The plan for %s has breaking changes; they need a reindex, never an in-place apply.', $plan->index->value));
        }
        $fields = $this->stored($plan->index)['fields'];
        foreach ($plan->additive() as $change) {
            if ($change->kind === ChangeKind::Added && $change->after !== null) {
                $fields = self::addAt($fields, explode('.', $change->path), $change->after);
            }
        }
        $this->indices[$plan->index->value]['fields'] = $fields;

        return PendingTask::completed();
    }

    #[Override]
    public function drop(IndexName $index): void
    {
        unset($this->indices[$index->value]);
    }

    #[Override]
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $declared = $this->stored($index)['fields'];
        $outcomes = [];
        foreach ($docs as $doc) {
            $undeclared = self::undeclared($doc->fields, $declared);
            if ($undeclared !== null) {
                $outcomes[] = WriteOutcome::failed($doc->id, sprintf('%s is not a declared field.', Assert::show($undeclared)));
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
     * The first key of `$source` that the fields do not declare, looking into objects.
     *
     * @param array<array-key, mixed> $source
     * @param list<Field> $fields
     */
    private static function undeclared(array $source, array $fields): ?string
    {
        $byName = Field::byName($fields);
        foreach ($source as $key => $value) {
            $field = $byName[(string) $key] ?? null;
            if ($field === null) {
                return (string) $key;
            }
            if ($field->fields === [] || !is_array($value)) {
                continue;
            }
            foreach (array_is_list($value) ? $value : [$value] as $child) {
                $inner = is_array($child) ? self::undeclared($child, $field->fields) : null;
                if ($inner !== null) {
                    return $key . '.' . $inner;
                }
            }
        }

        return null;
    }

    /**
     * @param list<Field> $fields
     * @param list<string> $path
     * @return list<Field>
     */
    private static function addAt(array $fields, array $path, Field $field): array
    {
        if (count($path) === 1) {
            return [...$fields, $field];
        }
        $head = $path[0];

        return array_map(static function (Field $existing) use ($head, $path, $field): Field {
            if ($existing->name !== $head) {
                return $existing;
            }
            $children = self::addAt($existing->fields, array_slice($path, 1), $field);

            return $existing->type === FieldType::Nested ? Field::nested($existing->name, ...$children) : Field::object($existing->name, ...$children);
        }, $fields);
    }
}
