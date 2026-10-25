<?php

declare(strict_types=1);

namespace Orion;

use Orion\Wiring\Config;
use Orion\Wiring\Graph;

/**
 * The entry point: `Orion::create(new Config(...))` builds the object graph without a container. Host
 * integrations take the {@see Graph} this facade exposes.
 */
final class Orion
{
    private function __construct(private readonly Graph $graph)
    {
    }

    public static function create(Config $config): self
    {
        return new self(new Graph($config));
    }

    public function graph(): Graph
    {
        return $this->graph;
    }
}
