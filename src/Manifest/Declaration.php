<?php

declare(strict_types=1);

namespace Orion\Manifest;

use Orion\Schema\IndexSchema;

/**
 * One index as a `search:` manifest declares it: its schema, the engine it is built on (null: the application's only
 * engine), and the file it came from.
 */
final readonly class Declaration
{
    public function __construct(public IndexSchema $schema, public ?string $engine, public string $file)
    {
    }
}
