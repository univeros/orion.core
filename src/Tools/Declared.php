<?php

declare(strict_types=1);

namespace Orion\Tools;

use InvalidArgumentException;
use Orion\IndexName;
use Orion\Schema\IndexSchema;
use Orion\Schema\MigrationPlan;
use Orion\Support\Assert;
use Orion\Support\Input;
use Orion\Wiring\Graph;

use function count;
use function implode;
use function sprintf;

/**
 * What the tools share: the index named in an input, its declared schema (the `search:` manifest is the only
 * declaration an agent tool reads; none constructs a schema), the engine that serves it, and where a declaration
 * stands against the index.
 */
final class Declared
{
    /**
     * @param array<string, mixed> $input
     */
    public static function index(array $input): IndexName
    {
        return IndexName::of(Input::string($input['index'] ?? null, 'input.index'));
    }

    public static function schema(Graph $graph, IndexName $index): IndexSchema
    {
        return $graph->manifest()->find($index->value)->schema
            ?? throw new InvalidArgumentException(sprintf('No search: manifest declares %s.', Assert::show($index->value)));
    }

    /**
     * The engine that built the index, else the one its manifest names, else the only one configured.
     */
    public static function engine(Graph $graph, IndexName $index): ?string
    {
        $built = $graph->aliases()->current($index)->engine ?? $graph->manifest()->find($index->value)?->engine;
        $names = $graph->engines()->names();

        return $built ?? (count($names) === 1 ? $names[0] : null);
    }

    public static function requireEngine(Graph $graph, IndexName $index): string
    {
        return self::engine($graph, $index)
            ?? throw new InvalidArgumentException(sprintf('%s is not built and several engines are configured (%s): name one in the manifest.', Assert::show($index->value), implode(', ', $graph->engines()->names())));
    }

    /**
     * Where a declared schema stands against the index: missing (not built), in sync, additive or breaking drift.
     */
    public static function state(MigrationPlan $plan, bool $built): string
    {
        return match (true) {
            !$built => 'missing',
            $plan->isEmpty() => 'in_sync',
            $plan->requiresReindex() => 'breaking',
            default => 'additive',
        };
    }

    /**
     * A JSON schema for an object input.
     *
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }
}
