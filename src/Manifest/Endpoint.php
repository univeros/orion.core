<?php

declare(strict_types=1);

namespace Orion\Manifest;

use InvalidArgumentException;
use Orion\Query\Direction;
use Orion\Query\Fusion;
use Orion\Query\FusionStrategy;
use Orion\Query\Page;
use Orion\Query\Query;
use Orion\Query\Sort;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_is_list;
use function array_keys;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * The search endpoint an index serves, as the manifest's `search` block declares it (v1 §12): the route, the
 * permission the host enforces, the text fields a `q` reads with their weights, the optional hybrid leg, and the
 * fields a request may filter, sort and facet by. Every field is checked against the schema when the manifest
 * loads, so a request can only ever name what the index declares and flags.
 */
final readonly class Endpoint
{
    public const array METHODS = ['GET', 'POST'];
    private const string PATH = '#^/[A-Za-z0-9_./-]*\z#';
    private const string PERMISSION = '/^[A-Za-z0-9_.:-]{1,255}\z/';

    /**
     * @param array<string, float> $queryFields weight by text field
     * @param list<string> $filterable
     * @param list<string> $sortable field names, `_score` included
     * @param list<string> $facets
     * @param list<string> $highlight
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $queryFields,
        public ?string $vectorField = null,
        public ?Fusion $fusion = null,
        public array $filterable = [],
        public array $sortable = [],
        public array $facets = [],
        public array $highlight = [],
        public ?Sort $defaultSort = null,
        public int $pageSize = Query::DEFAULT_PAGE_SIZE,
        public ?string $permission = null,
    ) {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('An endpoint is served by %s, not %s.', 'GET or POST', Assert::show($method)));
        }
        if (preg_match(self::PATH, $path) !== 1) {
            throw new InvalidArgumentException(sprintf('The endpoint path %s is not a plain path.', Assert::show($path)));
        }
        if ($queryFields === []) {
            throw new InvalidArgumentException('An endpoint needs at least one query field.');
        }
        if ($vectorField !== null && $method !== 'POST') {
            throw new InvalidArgumentException('A hybrid endpoint takes its vector in a JSON body: serve it with POST.');
        }
        if ($vectorField === null && $fusion !== null) {
            throw new InvalidArgumentException('A fusion needs a hybrid vector field.');
        }
        if ($fusion !== null && $fusion->strategy !== FusionStrategy::ReciprocalRank) {
            throw new InvalidArgumentException('Core fuses by reciprocal rank only; weighted fusion needs an engine with native fusion, which none has.');
        }
        if ($vectorField !== null && $defaultSort !== null && $defaultSort->field !== Sort::SCORE) {
            throw new InvalidArgumentException(sprintf('A hybrid endpoint ranks by fusion: its default sort cannot be %s.', Assert::show($defaultSort->field)));
        }
        Assert::atMost(Assert::positive($pageSize, 'The page size'), Page::MAX_SIZE, 'The page size');
        if ($permission !== null && preg_match(self::PERMISSION, $permission) !== 1) {
            throw new InvalidArgumentException(sprintf('The permission %s is not a permission key.', Assert::show($permission)));
        }
        if ($defaultSort !== null && !in_array($defaultSort->field, $sortable, true) && $defaultSort->field !== Sort::SCORE) {
            throw new InvalidArgumentException(sprintf('The default sort %s is not among the sortable fields.', Assert::show($defaultSort->field)));
        }
    }

    /**
     * Reads the `search` block and checks every field against the schema.
     */
    public static function fromArray(mixed $data, IndexSchema $schema, string $path): self
    {
        $data = Input::object($data, $path, ['endpoint', 'query_fields'], ['permission', 'hybrid', 'filterable', 'sortable', 'facets', 'highlight', 'default_sort', 'page_size']);
        [$method, $route] = self::route(Input::string($data['endpoint'], $path . '.endpoint'), $path . '.endpoint');
        $queryFields = self::weights($data['query_fields'], $path . '.query_fields');
        foreach (array_keys($queryFields) as $field) {
            self::typed($schema, $field, [FieldType::Text, FieldType::Keyword], $path . '.query_fields', 'a text or keyword field');
        }
        [$vectorField, $fusion] = isset($data['hybrid']) ? self::hybrid($data['hybrid'], $schema, $path . '.hybrid') : [null, null];
        $filterable = self::names($data['filterable'] ?? [], $path . '.filterable');
        foreach ($filterable as $field) {
            if (!self::field($schema, $field, $path . '.filterable')->filterable) {
                throw new InvalidArgumentException(sprintf('%s names %s, which is not filterable.', $path . '.filterable', Assert::show($field)));
            }
        }
        $sortable = self::names($data['sortable'] ?? [], $path . '.sortable');
        foreach ($sortable as $field) {
            if ($field !== Sort::SCORE && !self::field($schema, $field, $path . '.sortable')->sortable) {
                throw new InvalidArgumentException(sprintf('%s names %s, which is not sortable.', $path . '.sortable', Assert::show($field)));
            }
        }
        $facets = self::names($data['facets'] ?? [], $path . '.facets');
        foreach ($facets as $field) {
            if (!self::field($schema, $field, $path . '.facets')->facetable) {
                throw new InvalidArgumentException(sprintf('%s names %s, which is not facetable.', $path . '.facets', Assert::show($field)));
            }
        }
        $highlight = self::names($data['highlight'] ?? [], $path . '.highlight');
        foreach ($highlight as $field) {
            self::typed($schema, $field, [FieldType::Text], $path . '.highlight', 'a text field');
        }

        return new self(
            $method,
            $route,
            $queryFields,
            $vectorField,
            $fusion,
            $filterable,
            $sortable,
            $facets,
            $highlight,
            isset($data['default_sort']) ? self::sort(Input::string($data['default_sort'], $path . '.default_sort')) : null,
            isset($data['page_size']) ? Input::int($data['page_size'], $path . '.page_size') : Query::DEFAULT_PAGE_SIZE,
            isset($data['permission']) ? Input::string($data['permission'], $path . '.permission') : null,
        );
    }

    /**
     * `field` ascending or `-field` descending, as requests spell a sort too.
     */
    public static function sort(string $spec): Sort
    {
        return str_starts_with($spec, '-') ? new Sort(substr($spec, 1), Direction::Desc) : new Sort($spec, Direction::Asc);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->method . ' ' . $this->path,
            ...($this->permission === null ? [] : ['permission' => $this->permission]),
            'query_fields' => $this->queryFields,
            ...($this->vectorField === null ? [] : ['hybrid' => ['vector_field' => $this->vectorField, ...($this->fusion === null ? [] : ['fusion' => $this->fusion->toArray()])]]),
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
            'facets' => $this->facets,
            'highlight' => $this->highlight,
            ...($this->defaultSort === null ? [] : ['default_sort' => ($this->defaultSort->direction === Direction::Desc ? '-' : '') . $this->defaultSort->field]),
            'page_size' => $this->pageSize,
        ];
    }

    /**
     * @return array{string, string}
     */
    private static function route(string $endpoint, string $path): array
    {
        if (preg_match('#^([A-Z]+) (/\S*)\z#', $endpoint, $match) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be "METHOD /path", %s given.', $path, Assert::show($endpoint)));
        }

        return [$match[1], $match[2]];
    }

    /**
     * @return array{string, Fusion|null}
     */
    private static function hybrid(mixed $data, IndexSchema $schema, string $path): array
    {
        $data = Input::object($data, $path, ['vector_field'], ['fusion']);
        $field = Input::string($data['vector_field'], $path . '.vector_field');
        self::typed($schema, $field, [FieldType::Vector], $path . '.vector_field', 'a vector field');
        $fusion = $data['fusion'] ?? null;
        if (is_string($fusion)) {
            $fusion = ['strategy' => $fusion];
        }

        return [$field, $fusion === null ? null : Fusion::fromArray($fusion, $path . '.fusion')];
    }

    /**
     * @return array<string, float>
     */
    private static function weights(mixed $data, string $path): array
    {
        if (!is_array($data) || $data === []) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty list of fields or weights by field.', $path));
        }
        $weights = [];
        if (array_is_list($data)) {
            foreach ($data as $i => $field) {
                $weights[Assert::fieldPath(Input::string($field, $path . '[' . $i . ']'), 'In ' . $path . ', the field')] = 1.0;
            }
        } else {
            foreach ($data as $field => $weight) {
                $name = Assert::fieldPath((string) $field, 'In ' . $path . ', the field');
                $weights[$name] = (float) Input::number($weight, $path . '.' . $field);
                if ($weights[$name] <= 0) {
                    throw new InvalidArgumentException(sprintf('%s.%s must be a positive weight.', $path, $name));
                }
            }
        }

        return $weights;
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $data, string $path): array
    {
        $names = [];
        foreach (Input::list($data, $path) as $i => $name) {
            $names[] = Input::string($name, $path . '[' . $i . ']');
        }

        return $names;
    }

    /**
     * @param list<FieldType> $types
     */
    private static function typed(IndexSchema $schema, string $name, array $types, string $path, string $expected): void
    {
        if (!in_array(self::field($schema, $name, $path)->type, $types, true)) {
            throw new InvalidArgumentException(sprintf('%s names %s, which is not %s.', $path, Assert::show($name), $expected));
        }
    }

    private static function field(IndexSchema $schema, string $name, string $path): Field
    {
        return $schema->fieldAt(Assert::fieldPath($name, 'In ' . $path . ', the field'))
            ?? throw new InvalidArgumentException(sprintf('%s names %s, which the schema does not declare.', $path, Assert::show($name)));
    }
}
