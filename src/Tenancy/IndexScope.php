<?php

declare(strict_types=1);

namespace Orion\Tenancy;

use InvalidArgumentException;
use LogicException;
use Orion\Query\Contribution;
use Orion\Query\Filter;
use Orion\Query\FusionTrace;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Support\Assert;

use function array_key_exists;
use function array_map;
use function sprintf;

/**
 * One operation's tenant on one shared index (v1 §9): the filter injected beneath every query, the engine ids its
 * writes use, the org key stamped into its documents, and the check that every hit coming back is its own.
 * Unscoped under single tenancy, where every method is the identity.
 */
final readonly class IndexScope
{
    private function __construct(public ?TenantScope $tenant, public ?string $orgKey)
    {
    }

    public static function unscoped(): self
    {
        return new self(null, null);
    }

    public static function of(TenantScope $tenant, string $orgKey): self
    {
        return new self($tenant, $orgKey);
    }

    /**
     * @return list<Filter>
     */
    public function filters(): array
    {
        return $this->tenant === null || $this->orgKey === null ? [] : [Filter::term($this->orgKey, $this->tenant->tenant)];
    }

    /**
     * The query with the tenant filter ANDed beneath its own: no filter the caller writes can widen it.
     */
    public function query(Query $query): Query
    {
        foreach ($this->filters() as $filter) {
            $query = $query->filter($filter);
        }

        return $query;
    }

    public function id(string $id): string
    {
        return $this->tenant?->qualify($id) ?? $id;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws InvalidArgumentException when the document names another tenant
     */
    public function fields(array $fields): array
    {
        if ($this->tenant === null || $this->orgKey === null) {
            return $fields;
        }
        if (array_key_exists($this->orgKey, $fields) && $fields[$this->orgKey] !== $this->tenant->tenant) {
            throw new InvalidArgumentException(sprintf('The document names another tenant in %s than the one writing it.', Assert::show($this->orgKey)));
        }

        return [...$fields, $this->orgKey => $this->tenant->tenant];
    }

    /**
     * The results with the application's ids.
     *
     * @throws LogicException when the engine returned a hit outside the tenant: it is never handed back
     */
    public function results(ResultSet $results): ResultSet
    {
        if ($this->tenant === null) {
            return $results;
        }
        $hits = array_map(fn(Hit $hit): Hit => new Hit($this->own($hit->id), $hit->score, $hit->source, $hit->highlights), $results->hits);
        $fusion = $results->fusion === null ? null : new FusionTrace($results->fusion->fusion, array_map(
            fn(Contribution $c): Contribution => new Contribution($this->own($c->id), $c->source, $c->rank, $c->score),
            $results->fusion->contributions,
        ));

        return new ResultSet($hits, $results->total, $results->totalIsEstimate, $results->facets, $results->tookMs, $fusion);
    }

    private function own(string $engineId): string
    {
        return $this->tenant?->unqualify($engineId)
            ?? throw new LogicException(sprintf('The engine returned %s, which is outside the tenant\'s scope; nothing was returned.', Assert::show($engineId)));
    }
}
