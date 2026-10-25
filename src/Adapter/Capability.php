<?php

declare(strict_types=1);

namespace Orion\Adapter;

/**
 * What an engine can do (v1 §7, §16). False is honest, not broken: core routes around it or refuses with
 * {@see UnsupportedClause}.
 */
enum Capability
{
    case Vectors;
    /** Native fusion; false means Orion fuses in core with reciprocal rank. */
    case HybridFusion;
    case NestedDocuments;
    case Facets;
    case Highlighting;
    /** False on Meilisearch; the alias swap is emulated in core. */
    case NativeAliases;
    /** True on Meilisearch. */
    case AsyncWrites;
    case CursorPagination;
    case FunctionScore;
    /** Solr streaming expressions. */
    case Streaming;
    /** Version-stamped writes, required for lossless replay. */
    case OptimisticConcurrency;
}
