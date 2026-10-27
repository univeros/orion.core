<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Schema\Field;
use Orion\Support\Input;
use Orion\Wiring\Graph;
use Override;

final readonly class DescribeIndex implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'describe_index';
    public const string DESCRIPTION = 'Describe a declared index: its declared schema (fields, types, flags, analyzers, org key), the fields the live index holds, and the current physical index and version.';

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
        $declared = Declared::schema($this->graph, $index);
        $current = $this->graph->aliases()->current($index);
        $live = $current === null ? null : $this->graph->engines()->get($current->engine)->inspect($this->graph->aliases()->targetOf($current));
        $fields = null;
        if ($live !== null) {
            $fields = [];
            foreach ($live->fields as $field) {
                if ($field instanceof Field) {
                    $fields[$field->name] = $field->toArray();
                }
            }
        }

        return [
            'index' => $index->value,
            'engine' => Declared::engine($this->graph, $index),
            'declared' => $declared->toArray(),
            'live' => $fields,
            'current' => $current === null ? null : ['physical' => $current->physical->value, 'version' => $current->version, 'schema_hash' => $current->schema->hash()],
        ];
    }
}
