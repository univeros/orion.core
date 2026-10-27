<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Lifecycle\IndexNamer;
use Orion\Lifecycle\Reindexer;
use Orion\Support\Assert;
use Orion\Support\Input;
use Orion\Wiring\Graph;
use Override;

/**
 * Mutating, gated (v1 §15): without `confirm`, the call is a preview of the rebuild and changes nothing.
 */
final readonly class Reindex implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'reindex';
    public const string DESCRIPTION = 'Rebuild an index from the outbox with its declared schema and swap the alias, with a receipt; run again to resume an interrupted one. An operator action over the whole index: it replays every tenant. Without confirm, previews the rebuild and changes nothing.';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function description(): string
    {
        return self::DESCRIPTION;
    }

    #[Override]
    public function inputSchema(): array
    {
        return Declared::object([
            'index' => ['type' => 'string', 'description' => 'The index name (alias) as the manifest declares it.'],
            'confirm' => ['type' => 'boolean', 'description' => 'true to rebuild; omitted or false previews.'],
            'batch' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Outbox entries per replay batch.'],
        ], ['index']);
    }

    #[Override]
    public function mutates(): bool
    {
        return true;
    }

    #[Override]
    public function call(array $input): array
    {
        $input = Input::object($input, 'input', ['index'], ['confirm', 'batch']);
        $index = Declared::index($input);
        $confirmed = isset($input['confirm']) && Input::bool($input['confirm'], 'input.confirm');
        $batch = isset($input['batch']) ? Input::int($input['batch'], 'input.batch') : Reindexer::DEFAULT_BATCH;
        Assert::positive($batch, 'input.batch');
        $schema = Declared::schema($this->graph, $index);
        $engine = Declared::requireEngine($this->graph, $index);
        $reindexer = $this->graph->reindexer($engine);
        if ($confirmed) {
            return $reindexer->reindex($schema, $batch)->toArray();
        }
        $pending = $reindexer->pending($index);
        $plan = $this->graph->planner($engine)->plan($schema);

        return [
            'index' => $index->value,
            'engine' => $engine,
            'dry_run' => true,
            'requires_reindex' => $plan->requiresReindex(),
            'plan' => $plan->toArray(),
            'pending' => $pending?->toArray(),
            'next' => ($pending === null ? IndexNamer::physical($index, $this->graph->registry()->nextVersion($index)) : $pending->physical)->value,
            'entries' => $this->graph->outbox()->size($index),
        ];
    }
}
