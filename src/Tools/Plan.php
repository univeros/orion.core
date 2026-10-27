<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Support\Input;
use Orion\Wiring\Graph;
use Override;

final readonly class Plan implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'plan';
    public const string DESCRIPTION = 'Diff the declared schema of an index against the live index and classify every change (missing, in_sync, additive, breaking), without changing anything.';

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
        return Declared::object(['index' => ['type' => 'string', 'description' => 'The index name (alias) as the manifest declares it.']], ['index']);
    }

    #[Override]
    public function mutates(): bool
    {
        return false;
    }

    #[Override]
    public function call(array $input): array
    {
        $index = Declared::index(Input::object($input, 'input', ['index']));
        $schema = Declared::schema($this->graph, $index);
        $engine = Declared::requireEngine($this->graph, $index);
        $built = $this->graph->aliases()->current($index) !== null;
        $plan = $this->graph->planner($engine)->plan($schema);

        return [
            'index' => $index->value,
            'engine' => $engine,
            'state' => Declared::state($plan, $built),
            'requires_reindex' => $built && $plan->requiresReindex(),
            'plan' => $plan->toArray(),
        ];
    }
}
