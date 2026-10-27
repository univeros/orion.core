<?php

declare(strict_types=1);

namespace Orion\Query;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\QueryCompiling;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\UnsupportedClause;
use Orion\IndexName;
use Orion\Lifecycle\AliasManager;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Support\Assert;
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\ScopeInjector;

use function array_reverse;
use function array_slice;
use function count;
use function min;
use function sprintf;

/**
 * The one way Orion searches (v1 §9): the index the alias serves now, on its engine, the query bound to the
 * registered schema (fields, types, flags and boosts checked once for every engine), the tenant's scope injected
 * beneath it, the escape hatch included, and every hit checked to be the tenant's before it is handed back with the
 * application's id. A hybrid query on an engine without native fusion is fused here (v1 §5.1): each leg is built
 * from the scoped query, so the tenant filter applies before either ranking, and reciprocal rank fusion is the
 * default.
 */
final readonly class Searcher
{
    public const string LEXICAL = 'lexical';
    public const string VECTOR = 'vector';
    /** How deep core fusion reads each leg: the whole window would cost two full scans per page. */
    public const int FUSION_WINDOW = Page::MAX_SIZE;

    public function __construct(private AliasManager $aliases, private EngineRegistry $engines, private ScopeInjector $scopes)
    {
    }

    /**
     * @throws NoTenant under a resolver, when the context has no tenant
     * @throws InvalidArgumentException when the query does not fit the index's schema, or the index is not built
     * @throws UnsupportedClause when the page is past the offset window, or the fusion asked for is not computable
     */
    public function search(Query $query): ResultSet
    {
        // The tenant first: a request without one learns nothing, not even whether the index is built.
        $this->scopes->current();
        $current = $this->current($query->index);
        $scope = $this->scopes->scope($current->schema);
        $engine = $this->engines->get($current->engine);
        $query = self::bound($query, $current);
        $target = $this->aliases->targetOf($current);
        $scoped = $scope->query($query);
        if (self::fusedInCore($engine, $query)) {
            return $scope->results($this->fuse($engine, $target, $scoped, $current->engine));
        }

        return $scope->results($engine->search($target, $scoped));
    }

    /**
     * What `search()` would send for the query, without sending it (v1 §15, `compile_query`): the same tenant,
     * binding, window and scope, then the engine's native form of the scoped query, or of each leg when core fuses.
     *
     * @throws LogicException when the engine cannot show its native query
     * @throws NoTenant|InvalidArgumentException|UnsupportedClause as `search()` would
     */
    public function compile(Query $query): Compilation
    {
        $this->scopes->current();
        $current = $this->current($query->index);
        $scope = $this->scopes->scope($current->schema);
        $engine = $this->engines->get($current->engine);
        if (!$engine instanceof QueryCompiling) {
            throw new LogicException(sprintf('The engine %s cannot show its native query.', $current->engine));
        }
        $query = self::bound($query, $current);
        $target = $this->aliases->targetOf($current);
        $scoped = $scope->query($query);
        if (!self::fusedInCore($engine, $query)) {
            return new Compilation($current->engine, $target, $scoped, $engine->compile($target, $scoped), null, null);
        }
        self::reversed($scoped);
        [$depth, $k] = self::legs($scoped, $current->engine);

        return new Compilation($current->engine, $target, $scoped, null, [
            self::LEXICAL => $engine->compile($target, $scoped->lexicalLeg($depth)),
            self::VECTOR => $engine->compile($target, $scoped->vectorLeg($k)),
        ], $scoped->fusion ?? Fusion::reciprocalRank());
    }

    /**
     * The native escape hatch: `$native` is the engine's own query (each adapter documents its shape), restricted
     * to the tenant by the adapter.
     */
    public function raw(IndexName $index, callable $native): ResultSet
    {
        $this->scopes->current();
        $current = $this->current($index);
        $scope = $this->scopes->scope($current->schema);

        return $scope->results($this->engines->get($current->engine)->raw($this->aliases->targetOf($current), $native, ...$scope->filters()));
    }

    /**
     * Both legs ranked by the engine on the scoped query, fused in core. Facets and highlights come from the lexical
     * leg; the total counts the fused candidates and is therefore an estimate; a sort other than relevance has no
     * meaning over a fused ranking and is refused (relevance ascending reverses it).
     */
    private function fuse(SearchAdapter $engine, IndexName $target, Query $query, string $name): ResultSet
    {
        $reversed = self::reversed($query);
        [$depth, $k] = self::legs($query, $name);
        $fusion = $query->fusion ?? Fusion::reciprocalRank();
        $lexical = $engine->search($target, $query->lexicalLeg($depth));
        $vector = $engine->search($target, $query->vectorLeg($k));
        $fused = $fusion->fuse([self::LEXICAL => $lexical->hits, self::VECTOR => $vector->hits]);
        $hits = array_slice($reversed ? array_reverse($fused->hits) : $fused->hits, ($query->page->page - 1) * $query->page->size, $query->page->size);

        return new ResultSet($hits, count($fused->hits), true, $lexical->facets, $lexical->tookMs + $vector->tookMs, $fused->trace);
    }

    /**
     * The query bound to the registered schema, within the offset window.
     */
    private static function bound(Query $query, IndexRegistration $current): Query
    {
        $query = Binder::bind($query, $current->schema);
        if ($query->page->depth() > Page::WINDOW) {
            throw UnsupportedClause::deepPaging(Page::WINDOW, $query->page->depth(), $current->engine);
        }

        return $query;
    }

    private static function fusedInCore(SearchAdapter $engine, Query $query): bool
    {
        return $query->vector !== null && $query->clauses !== [] && !$engine->supports(Capability::HybridFusion);
    }

    /**
     * Whether the fused ranking is read in reverse: a sort other than relevance has no meaning over it and is refused.
     */
    private static function reversed(Query $query): bool
    {
        $reversed = false;
        foreach ($query->sorts as $sort) {
            if ($sort->field !== Sort::SCORE) {
                throw new InvalidArgumentException(sprintf('A hybrid query ranks by fusion: it cannot sort by %s.', Assert::show($sort->field)));
            }
            $reversed = $sort->direction === Direction::Asc;
        }

        return $reversed;
    }

    /**
     * How deep each leg reads: the page's depth for the lexical leg, at most the vector's k for the vector leg.
     *
     * @return array{int, int}
     */
    private static function legs(Query $query, string $name): array
    {
        $depth = $query->page->depth();
        if ($depth > self::FUSION_WINDOW) {
            throw UnsupportedClause::deepPaging(self::FUSION_WINDOW, $depth, $name . ' with core fusion');
        }

        return [$depth, min($depth, $query->vector->k ?? $depth)];
    }

    private function current(IndexName $alias): IndexRegistration
    {
        return $this->aliases->current($alias)
            ?? throw new InvalidArgumentException(sprintf('%s is not built: apply its schema first.', Assert::show($alias->value)));
    }
}
