<?php

declare(strict_types=1);

namespace Orion\Tests\Storage;

use InvalidArgumentException;
use Orion\Storage\Column;
use Orion\Storage\ColumnType;
use Orion\Storage\Condition;
use Orion\Storage\Schema;
use Orion\Storage\Table;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

final class SchemaTest extends TestCase
{
    public function testOrionOwnsFourTables(): void
    {
        $names = array_map(static fn(Table $table): string => $table->name, Schema::tables());

        self::assertSame(['orion_outbox', 'orion_index', 'orion_alias', 'orion_receipt'], $names);
    }

    public function testEveryTableHasAPrimaryKey(): void
    {
        foreach (Schema::tables() as $table) {
            self::assertNotSame([], $table->primaryKey(), $table->name);
        }
    }

    public function testTheOutboxIsAddressableByOffset(): void
    {
        $outbox = Schema::table(Schema::OUTBOX);

        self::assertSame(ColumnType::Int, $outbox->column('outbox_offset')->type);
        self::assertTrue($outbox->indexes[0]->unique);
        self::assertSame(['outbox_offset'], $outbox->indexes[0]->columns);
    }

    public function testTheRegistryHoldsOneRowPerPhysicalIndexAndOnePerAlias(): void
    {
        $index = Schema::table(Schema::INDEX);
        $alias = Schema::table(Schema::ALIAS);

        self::assertSame(['name'], array_map(static fn(Column $c): string => $c->name, $index->primaryKey()));
        self::assertSame(['name', 'alias', 'engine', 'version', 'definition', 'schema_hash', 'created_at'], array_map(static fn(Column $c): string => $c->name, $index->columns));
        self::assertSame(ColumnType::Int, $index->column('version')->type);
        self::assertSame(ColumnType::Json, $index->column('definition')->type);
        self::assertTrue($index->indexes[0]->unique);
        self::assertSame(['alias', 'version'], $index->indexes[0]->columns);
        self::assertSame(['alias'], array_map(static fn(Column $c): string => $c->name, $alias->primaryKey()));
        self::assertSame(['alias', 'engine', 'physical', 'swapped_at'], array_map(static fn(Column $c): string => $c->name, $alias->columns));
    }

    public function testColumnsAreImmutable(): void
    {
        $column = Column::string('name', 80);

        self::assertFalse($column->nullable);
        self::assertTrue($column->nullable()->nullable);
        self::assertFalse($column->nullable);
    }

    public function testAnUnknownColumnOrTableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::table(Schema::OUTBOX)->column('missing');
    }

    public function testIdentifiersMustBeSafeForEverySqlDialect(): void
    {
        foreach (['a; drop table x', 'a b', "x\n", 'Upper', str_repeat('a', 64), ''] as $invalid) {
            try {
                Column::int($invalid);
                self::fail("'$invalid' was accepted as an identifier.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testConditionValuesAreScalarsNullOrDates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Condition::lt(['nested']);
    }

    public function testAnUnknownTableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::table('missing');
    }
}
