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
    /**
     * The transactional write log (v1 §10), compacted to the latest write or delete of each document per index and
     * addressable by offset per index. Offsets come from the `outbox:<index>` counter, so they are dense and committed
     * in order.
     */
    public const string OUTBOX = 'orion_outbox';
    /** Named integers: the outbox head per index (`outbox:<index>`), each drain cursor (`drain:<index>`), the receipt sequence. */
    public const string COUNTER = 'orion_counter';
    /** Outbox entries the engine refused, one row per entry, kept for retry (v1 §10). */
    public const string DEAD_LETTER = 'orion_dead_letter';
    /** The index registry: one row per versioned physical index with the schema it was built from (v1 §8.2). */
    public const string INDEX = 'orion_index';
    /** Which physical index each alias points at, for every engine; the only indirection on engines without native aliases. */
    public const string ALIAS = 'orion_alias';
    /** The append-only, hash-chained, signed receipts (docs/spec/revision.md §3). */
    public const string RECEIPT = 'orion_receipt';

    /**
     * @return list<Table>
     */
    public static function tables(): array
    {
        return [self::outbox(), self::counter(), self::deadLetter(), self::index(), self::alias(), self::receipt()];
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
            Column::string('index_name', 255)->primary(),
            Column::int('outbox_offset')->primary(),
            Column::string('document_id', 255),
            Column::string('operation', 16),
            Column::json('payload')->nullable(),
            Column::datetime('recorded_at'),
        ])
            ->unique(['index_name', 'document_id']);
    }

    private static function counter(): Table
    {
        return Table::named(self::COUNTER, [
            Column::string('name', 300)->primary(),
            Column::int('value'),
        ]);
    }

    private static function deadLetter(): Table
    {
        return Table::named(self::DEAD_LETTER, [
            Column::string('index_name', 255)->primary(),
            Column::int('outbox_offset')->primary(),
            Column::string('document_id', 255),
            Column::text('error'),
            Column::int('attempts'),
            Column::datetime('failed_at'),
        ])
            ->index(['index_name', 'document_id']);
    }

    private static function index(): Table
    {
        return Table::named(self::INDEX, [
            Column::string('name', 255)->primary(),
            Column::string('alias', 255),
            Column::string('engine', 64),
            Column::int('version'),
            Column::json('definition'),
            Column::string('schema_hash', 64),
            Column::datetime('created_at'),
        ])
            ->unique(['alias', 'version']);
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
