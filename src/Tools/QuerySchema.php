<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Query\Filter;
use Orion\Query\Page;
use Orion\Query\Query;

/**
 * The JSON schema of a query's declarative form ({@see Query::fromArray()}), as the query tools take it. It names
 * the keys and their shapes; {@see Query::fromArray()} and the searcher's binding check every value against the
 * registered schema, so this stays a guide for the agent, not the gate.
 */
final class QuerySchema
{
    /**
     * @return array<string, mixed>
     */
    public static function input(): array
    {
        $clause = ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['match', 'phrase', 'prefix', 'term']],
            'field' => ['type' => 'string'],
            'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'number'], 'description' => 'Weights by field, instead of one field.'],
            'value' => ['description' => 'Text for match, phrase and prefix; a string, number or boolean for term.'],
            'boost' => ['type' => 'number', 'exclusiveMinimum' => 0],
        ], 'required' => ['type', 'value'], 'additionalProperties' => false];

        return Declared::object([
            'index' => ['type' => 'string', 'description' => 'The index name (alias) as the manifest declares it.'],
            'query' => ['type' => 'array', 'items' => $clause, 'maxItems' => Query::MAX_CLAUSES, 'description' => 'Lexical clauses, all of which must match.'],
            'vector' => ['type' => 'object', 'properties' => [
                'field' => ['type' => 'string'],
                'vector' => ['type' => 'array', 'items' => ['type' => 'number']],
                'k' => ['type' => 'integer', 'minimum' => 1],
            ], 'required' => ['field', 'vector', 'k'], 'additionalProperties' => false, 'description' => 'k-NN over a vector field; with clauses, a hybrid query.'],
            'fusion' => ['type' => 'object', 'properties' => [
                'strategy' => ['type' => 'string', 'enum' => ['reciprocal_rank', 'weighted']],
                'k' => ['type' => 'integer', 'minimum' => 1],
                'lexical' => ['type' => 'number'],
                'vector' => ['type' => 'number'],
            ], 'required' => ['strategy'], 'additionalProperties' => false],
            'filters' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'type' => ['type' => 'string', 'enum' => ['term', 'terms', 'range', 'exists', 'bool']],
            ], 'required' => ['type']], 'maxItems' => Query::MAX_FILTERS, 'description' => 'Filters by type: term (field, value), terms (field, values), range (field, gt/gte/lt/lte), exists (field), bool (must, should, must_not; at most ' . Filter::MAX_CHILDREN . ' children each, ' . Filter::MAX_DEPTH . ' levels deep).'],
            'sort' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'field' => ['type' => 'string', 'description' => 'A sortable field, or _score.'],
                'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ], 'required' => ['field'], 'additionalProperties' => false], 'maxItems' => Query::MAX_SORTS],
            'facets' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'field' => ['type' => 'string'],
                'size' => ['type' => 'integer', 'minimum' => 1],
            ], 'required' => ['field'], 'additionalProperties' => false], 'maxItems' => Query::MAX_FACETS],
            'highlight' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => Query::MAX_HIGHLIGHTS],
            'page' => ['type' => 'object', 'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Page::MAX_SIZE],
            ], 'required' => ['page', 'size'], 'additionalProperties' => false],
        ], ['index']);
    }
}
