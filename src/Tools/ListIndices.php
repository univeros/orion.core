<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Manifest\Declaration;
use Orion\Support\Input;
use Orion\Wiring\Graph;
use Override;

use function array_map;

final readonly class ListIndices implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'list_indices';
    public const string DESCRIPTION = 'List the indices the search: manifests declare: engine, whether the index is built, its current physical index and version, and the endpoint that serves it.';

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
        return Declared::object([]);
    }

    #[Override]
    public function mutates(): bool
    {
        return false;
    }

    #[Override]
    public function call(array $input): array
    {
        Input::object($input, 'input', []);

        return ['indices' => array_map($this->entry(...), $this->graph->manifest()->declarations())];
    }

    /**
     * @return array{index: string, engine: string|null, built: bool, physical: string|null, version: int|null, endpoint: string|null}
     */
    private function entry(Declaration $declaration): array
    {
        $index = $declaration->schema->index;
        $current = $this->graph->aliases()->current($index);

        return [
            'index' => $index->value,
            'engine' => Declared::engine($this->graph, $index),
            'built' => $current !== null,
            'physical' => $current?->physical->value,
            'version' => $current?->version,
            'endpoint' => $declaration->endpoint === null ? null : $declaration->endpoint->method . ' ' . $declaration->endpoint->path,
        ];
    }
}
