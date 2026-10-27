<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\IndexName;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_map;
use function count;
use function get_object_vars;

/**
 * The neutral query AST (v1 §5). The fluent builder is for people; {@see fromArray()} is the declarative
 * construction for agents, and the two produce the same AST. Every builder call returns a copy.
 */
final readonly class Query
{
    public const int DEFAULT_PAGE_SIZE = 20;
    /** Ceilings of the declarative form's lists, so one query cannot build an arbitrarily large request. */
    public const int MAX_CLAUSES = 64;
    public const int MAX_FILTERS = 64;
    public const int MAX_SORTS = 8;
    public const int MAX_FACETS = 16;
    public const int MAX_HIGHLIGHTS = 16;

    /**
     * @param list<Clause> $clauses scored, conjunctive
     * @param list<Filter> $filters non-scoring, conjunctive
     * @param list<Sort> $sorts
     * @param list<Facet> $facets
     * @param list<string> $highlights
     */
    private function __construct(
        public IndexName $index,
        public array $clauses = [],
        public ?VectorClause $vector = null,
        public ?Fusion $fusion = null,
        public array $filters = [],
        public array $sorts = [],
        public array $facets = [],
        public array $highlights = [],
        public Page $page = new Page(1, self::DEFAULT_PAGE_SIZE),
    ) {
    }

    public static function on(string $index): self
    {
        return new self(IndexName::of($index));
    }

    /**
     * @param string|list<string>|array<string, float> $fields one field, several at weight 1, or weights by field
     */
    public function match(string|array $fields, string $text): self
    {
        return $this->with(clauses: [...$this->clauses, Clause::match($fields, $text)]);
    }

    public function term(string $field, string|int|float|bool $value): self
    {
        return $this->with(clauses: [...$this->clauses, Clause::term($field, $value)]);
    }

    public function prefix(string $field, string $prefix): self
    {
        return $this->with(clauses: [...$this->clauses, Clause::prefix($field, $prefix)]);
    }

    public function phrase(string $field, string $phrase): self
    {
        return $this->with(clauses: [...$this->clauses, Clause::phrase($field, $phrase)]);
    }

    /**
     * @param list<float> $vector
     */
    public function vector(string $field, array $vector, int $k): self
    {
        return $this->with(vector: new VectorClause($field, $vector, $k));
    }

    public function fuse(Fusion $fusion): self
    {
        return $this->with(fusion: $fusion);
    }

    public function filter(Filter $filter): self
    {
        return $this->with(filters: [...$this->filters, $filter]);
    }

    public function sort(string $field, Direction $direction = Direction::Asc): self
    {
        return $this->with(sorts: [...$this->sorts, new Sort($field, $direction)]);
    }

    public function facet(string $field, int $size = Facet::DEFAULT_SIZE): self
    {
        return $this->with(facets: [...$this->facets, new Facet($field, $size)]);
    }

    public function highlight(string ...$fields): self
    {
        foreach ($fields as $field) {
            Assert::fieldPath($field, 'A highlight field');
        }

        return $this->with(highlights: [...$this->highlights, ...$fields]);
    }

    public function paginate(int $page = 1, int $size = self::DEFAULT_PAGE_SIZE): self
    {
        return $this->with(page: new Page($page, $size));
    }

    /**
     * A copy with these clauses, for the binder that weighs them.
     *
     * @param list<Clause> $clauses
     */
    public function withClauses(array $clauses): self
    {
        return $this->with(clauses: $clauses);
    }

    /**
     * The lexical leg of a hybrid query, for core fusion: the first `$depth` hits of the query without its vector
     * clause or sorts (each leg is ranked by relevance; the fusion orders), so an engine without native fusion can rank
     * it alone.
     */
    public function lexicalLeg(int $depth): self
    {
        return $this->with(vector: null, fusion: null, sorts: [], page: new Page(1, $depth));
    }

    /**
     * The vector leg: the first `$depth` nearest neighbours of the query without its lexical clauses. Facets and
     * highlights belong to the lexical leg.
     */
    public function vectorLeg(int $depth): self
    {
        return $this->with(clauses: [], fusion: null, sorts: [], facets: [], highlights: [], page: new Page(1, $depth));
    }

    /**
     * @return array{
     *     index: string,
     *     query: list<array<string, mixed>>,
     *     vector: array<string, mixed>|null,
     *     fusion: array<string, mixed>|null,
     *     filters: list<array<string, mixed>>,
     *     sort: list<array<string, mixed>>,
     *     facets: list<array<string, mixed>>,
     *     highlight: list<string>,
     *     page: array{page: int, size: int},
     * }
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index->value,
            'query' => array_map(static fn(Clause $clause): array => $clause->toArray(), $this->clauses),
            'vector' => $this->vector?->toArray(),
            'fusion' => $this->fusion?->toArray(),
            'filters' => array_map(static fn(Filter $filter): array => $filter->toArray(), $this->filters),
            'sort' => array_map(static fn(Sort $sort): array => $sort->toArray(), $this->sorts),
            'facets' => array_map(static fn(Facet $facet): array => $facet->toArray(), $this->facets),
            'highlight' => $this->highlights,
            'page' => $this->page->toArray(),
        ];
    }

    /**
     * Builds the AST from its declarative form; omitted keys take the builder's defaults.
     */
    public static function fromArray(mixed $data): self
    {
        $path = 'query';
        $data = Input::object($data, $path, ['index'], ['query', 'vector', 'fusion', 'filters', 'sort', 'facets', 'highlight', 'page']);
        $highlights = [];
        foreach (Input::list($data['highlight'] ?? [], $path . '.highlight') as $i => $field) {
            $highlights[] = Assert::fieldPath(Input::string($field, $path . '.highlight[' . $i . ']'), $path . '.highlight[' . $i . ']');
        }

        Assert::atMost(count($highlights), self::MAX_HIGHLIGHTS, $path . '.highlight');

        return new self(
            IndexName::of(Input::string($data['index'], $path . '.index')),
            self::each($data['query'] ?? [], $path . '.query', self::MAX_CLAUSES, Clause::fromArray(...)),
            isset($data['vector']) ? VectorClause::fromArray($data['vector'], $path . '.vector') : null,
            isset($data['fusion']) ? Fusion::fromArray($data['fusion'], $path . '.fusion') : null,
            self::each($data['filters'] ?? [], $path . '.filters', self::MAX_FILTERS, Filter::fromArray(...)),
            self::each($data['sort'] ?? [], $path . '.sort', self::MAX_SORTS, Sort::fromArray(...)),
            self::each($data['facets'] ?? [], $path . '.facets', self::MAX_FACETS, Facet::fromArray(...)),
            $highlights,
            isset($data['page']) ? Page::fromArray($data['page'], $path . '.page') : new Page(1, self::DEFAULT_PAGE_SIZE),
        );
    }

    /**
     * @template T
     * @param callable(mixed, string): T $read
     * @return list<T>
     */
    private static function each(mixed $data, string $path, int $max, callable $read): array
    {
        $list = Input::list($data, $path);
        Assert::atMost(count($list), $max, $path);
        $items = [];
        foreach ($list as $i => $item) {
            $items[] = $read($item, $path . '[' . $i . ']');
        }

        return $items;
    }

    /**
     * A copy with the named properties replaced.
     */
    private function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
