<?php

declare(strict_types=1);

namespace Orion\Tools;

use Orion\Adapter\Capability;
use Orion\Adapter\SearchAdapter;
use Orion\Support\Input;
use Orion\Wiring\Graph;
use Override;

final readonly class Capabilities implements Tool
{
    public function __construct(private Graph $graph)
    {
    }

    public const string NAME = 'capabilities';
    public const string DESCRIPTION = 'The capability matrix of each configured engine (vectors, native hybrid fusion, facets, highlighting, cursor pagination, ...), so a query only asks for what its engine supports.';

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
        return Declared::object(['engine' => ['type' => 'string', 'description' => 'One engine by name; omit for every engine.']]);
    }

    #[Override]
    public function mutates(): bool
    {
        return false;
    }

    #[Override]
    public function call(array $input): array
    {
        $input = Input::object($input, 'input', [], ['engine']);
        $names = isset($input['engine']) ? [Input::string($input['engine'], 'input.engine')] : $this->graph->engines()->names();
        $engines = [];
        foreach ($names as $name) {
            $engines[$name] = self::of($this->graph->engines()->get($name));
        }

        return ['engines' => $engines];
    }

    /**
     * @return array<string, bool>
     */
    public static function of(SearchAdapter $engine): array
    {
        $capabilities = [];
        foreach (Capability::cases() as $capability) {
            $capabilities[$capability->name] = $engine->supports($capability);
        }

        return $capabilities;
    }
}
