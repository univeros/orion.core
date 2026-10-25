<?php

declare(strict_types=1);

namespace Orion\Registry;

use InvalidArgumentException;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;

use function array_keys;
use function implode;
use function is_int;
use function sprintf;

/**
 * The named engine adapters an application runs: `new EngineRegistry(elasticsearch: $adapter)`. An engine
 * implements all three adapter roles.
 */
final readonly class EngineRegistry
{
    /** @var array<string, SearchAdapter&SchemaAdapter&WriteAdapter> */
    private array $engines;

    public function __construct(SearchAdapter&SchemaAdapter&WriteAdapter ...$engines)
    {
        if ($engines === []) {
            throw new InvalidArgumentException('At least one engine is required.');
        }
        $named = [];
        foreach ($engines as $name => $engine) {
            if (is_int($name)) {
                throw new InvalidArgumentException('Engines are registered by name: new EngineRegistry(elasticsearch: $adapter).');
            }
            $named[$name] = $engine;
        }
        $this->engines = $named;
    }

    public function get(string $name): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return $this->engines[$name] ?? throw new InvalidArgumentException(sprintf(
            'No engine "%s" is registered; registered: %s.',
            $name,
            implode(', ', $this->names()),
        ));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->engines);
    }
}
