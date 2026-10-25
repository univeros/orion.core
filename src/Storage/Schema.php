<?php

declare(strict_types=1);

namespace Orion\Storage;

use InvalidArgumentException;

use function sprintf;

/**
 * Every table Orion stores, as data: the source of truth for storage installers. Ids are UUID v7 strings.
 */
final class Schema
{
    /** The transactional write log, addressable by offset (v1 §10). Offset allocation is settled in WP4. */
    public const string OUTBOX = 'orion_outbox';
    /** Registered indices: engine, schema and current physical index. */
    public const string INDEX = 'orion_index';
    /** Alias indirection for engines without native aliases (v1 §8.2). */
    public const string ALIAS = 'orion_alias';
    /** The append-only, hash-chained, signed receipts (docs/spec/revision.md §3). */
    public const string RECEIPT = 'orion_receipt';

    /**
     * @return list<Table>
     */
    public static function tables(): array
    {
        return [self::outbox(), self::index(), self::alias(), self::receipt()];
    }

    public static function table(string $name): Table
    {
        foreach (self::tables() as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        throw new InvalidArgumentException(sprintf('Orion has no table "%s".', $name));
    }

    private static function outbox(): Table
    {
        return Table::named(self::OUTBOX, [
            Column::string('id', 36)->primary(),
            Column::int('outbox_offset'),
            Column::string('index_name', 255),
            Column::string('document_id', 255),
            Column::string('operation', 16),
            Column::json('payload')->nullable(),
            Column::datetime('recorded_at'),
        ])
            ->unique(['outbox_offset'])
            ->index(['index_name', 'outbox_offset']);
    }

    private static function index(): Table
    {
        return Table::named(self::INDEX, [
            Column::string('name', 255)->primary(),
            Column::string('engine', 64),
            Column::string('physical', 255)->nullable(),
            Column::json('definition'),
            Column::string('schema_hash', 64),
            Column::datetime('registered_at'),
            Column::datetime('updated_at'),
        ]);
    }

    private static function alias(): Table
    {
        return Table::named(self::ALIAS, [
            Column::string('alias', 255)->primary(),
            Column::string('engine', 64),
            Column::string('physical', 255),
            Column::datetime('swapped_at'),
        ]);
    }

    private static function receipt(): Table
    {
        return Table::named(self::RECEIPT, [
            Column::string('id', 36)->primary(),
            Column::int('sequence'),
            Column::string('operation', 32),
            Column::string('index_name', 255),
            Column::string('from_version', 255)->nullable(),
            Column::string('to_version', 255),
            Column::int('document_count'),
            Column::string('schema_hash', 64),
            Column::int('from_offset'),
            Column::int('to_offset'),
            Column::string('previous_hash', 64)->nullable(),
            Column::string('hash', 64),
            Column::string('kid', 64),
            Column::text('signature'),
            Column::datetime('recorded_at'),
        ])
            ->unique(['sequence'])
            ->index(['index_name', 'sequence']);
    }
}
