<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Support\Input;
use Orion\Wiring\Graph;
use Override;

/**
 * Mutating, gated (v1 §15): without `confirm`, the call is a preview of what would happen and changes nothing.
 */
final readonly class Apply implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'apply';
    public const string DESCRIPTION = 'Create the index from its declared schema, or apply additive changes in place with a receipt; a breaking change is refused (reindex instead). An operator action over the whole index, every tenant included. Without confirm, previews what would happen and changes nothing.';

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
            'confirm' => ['type' => 'boolean', 'description' => 'true to apply; omitted or false previews.'],
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
        $input = Input::object($input, 'input', ['index'], ['confirm']);
        $index = Declared::index($input);
        $confirmed = isset($input['confirm']) && Input::bool($input['confirm'], 'input.confirm');
        $schema = Declared::schema($this->graph, $index);
        $engine = Declared::requireEngine($this->graph, $index);
        if ($confirmed) {
            return $this->graph->planner($engine)->apply($schema)->toArray();
        }
        $current = $this->graph->aliases()->current($index);
        $plan = $this->graph->planner($engine)->plan($schema);
        $state = Declared::state($plan, $current !== null);

        return [
            'index' => $index->value,
            'engine' => $engine,
            'dry_run' => true,
            'would' => match ($state) {
                'missing' => 'create',
                'breaking' => 'reindex_required',
                'in_sync' => $current !== null && $current->schema->hash() !== $schema->hash() ? 'apply' : 'nothing',
                default => 'apply',
            },
            'plan' => $plan->toArray(),
        ];
    }
}
