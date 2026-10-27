<?php

declare(strict_types=1);

namespace Orion\Manifest;

use Orion\Schema\IndexSchema;

/**
 * One index as a `search:` manifest declares it: its schema, the engine it is built on (null: the application's only
 * engine), the file it came from, and when declared, where its documents come from and the endpoint it serves.
 */
final readonly class Declaration
{
    public function __construct(
        public IndexSchema $schema,
        public ?string $engine,
        public string $file,
        public ?Source $source = null,
        public ?Endpoint $endpoint = null,
    ) {
    }
}
