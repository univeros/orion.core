<?php

declare(strict_types=1);

namespace Orion\Tools;

use InvalidArgumentException;

/**
 * One operation of the agent surface (v1 §15): a name, a description an agent reads, a JSON schema for its input,
 * and the call itself, JSON in and JSON out. The core tools are framework-free; a host adapts them to its own MCP
 * server, keeping the name, description and schema. A tool that mutates says so, and does nothing until the input
 * confirms it.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed> the JSON schema of the input, an object
     */
    public function inputSchema(): array;

    public function mutates(): bool;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws InvalidArgumentException naming what in the input does not fit
     */
    public function call(array $input): array;
}
