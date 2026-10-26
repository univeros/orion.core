<?php

declare(strict_types=1);

namespace Orion\Tests\Schema;

use Orion\Adapter\RequiresReindex;
use Orion\IndexName;
use Orion\Schema\Change;
use Orion\Schema\ChangeKind;
use Orion\Schema\Classification;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Schema\VectorMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;

final class MigrationPlanTest extends TestCase
{
    public function testMatchingSchemasPlanNothing(): void
    {
        $desired = IndexSchema::for('products')->text('title', language: 'en')->keyword('sku', filterable: true);

        $plan = MigrationPlan::between($desired, self::live(...$desired->fields));

        self::assertTrue($plan->isEmpty());
        self::assertFalse($plan->requiresReindex());
    }

    public function testFieldOrderIsNotDrift(): void
    {
        $desired = IndexSchema::for('products')->keyword('a')->keyword('b');

        self::assertTrue(MigrationPlan::between($desired, self::live(Field::keyword('b'), Field::keyword('a')))->isEmpty());
    }

    public function testAMissingIndexPlansEveryFieldAsAdded(): void
    {
        $plan = MigrationPlan::between(IndexSchema::for('products')->keyword('sku')->text('title'), null);

        self::assertSame(['sku', 'title'], self::paths($plan->additive()));
        self::assertSame([], $plan->breaking());
    }

    public function testANewFieldIsAdditive(): void
    {
        $plan = MigrationPlan::between(
            IndexSchema::for('products')->keyword('sku')->keyword('brand', facetable: true),
            self::live(Field::keyword('sku')),
        );

        self::assertCount(1, $plan->changes);
        self::assertSame(ChangeKind::Added, $plan->changes[0]->kind);
        self::assertSame(Classification::Additive, $plan->changes[0]->classification);
        self::assertSame('brand', $plan->changes[0]->path);
        self::assertNull($plan->changes[0]->before);
        self::assertEquals(Field::keyword('brand', facetable: true), $plan->changes[0]->after);
        self::assertFalse($plan->requiresReindex());
    }

    public function testARemovedFieldIsBreaking(): void
    {
        $plan = MigrationPlan::between(IndexSchema::for('products')->keyword('sku'), self::live(Field::keyword('sku'), Field::keyword('legacy')));

        self::assertSame(ChangeKind::Removed, $plan->changes[0]->kind);
        self::assertSame(['legacy'], self::paths($plan->breaking()));
        self::assertTrue($plan->requiresReindex());
    }

    public function testABoostChangeIsNotIndexDriftBecauseItIsAppliedAtQueryTime(): void
    {
        $desired = IndexSchema::for('products')->text('title', boost: 3.0)->nested('v', Field::text('n', boost: 2.0));

        self::assertTrue(MigrationPlan::between($desired, self::live(Field::text('title'), Field::nested('v', Field::text('n'))))->isEmpty());
    }

    #[DataProvider('breakingChanges')]
    public function testEveryChangeToAnExistingFieldIsBreaking(Field $live, Field $desired): void
    {
        $plan = MigrationPlan::between(IndexSchema::for('products')->with($desired), self::live($live));

        self::assertSame([$desired->name], self::paths($plan->breaking()));
        self::assertSame([], $plan->additive());
        self::assertTrue($plan->requiresReindex());
    }

    /**
     * @return iterable<string, array{Field, Field}>
     */
    public static function breakingChanges(): iterable
    {
        yield 'type' => [Field::keyword('price'), Field::float('price')];
        yield 'language' => [Field::text('title', language: 'en'), Field::text('title', language: 'de')];
        yield 'folding' => [Field::text('title'), Field::text('title', folding: true)];
        yield 'filterable on' => [Field::keyword('sku'), Field::keyword('sku', filterable: true)];
        yield 'sortable off' => [Field::keyword('sku', sortable: true), Field::keyword('sku')];
        yield 'facetable' => [Field::keyword('brand'), Field::keyword('brand', facetable: true)];
        yield 'dims' => [Field::vector('embedding', dims: 768), Field::vector('embedding', dims: 1536)];
        yield 'metric' => [Field::vector('embedding', dims: 3), Field::vector('embedding', dims: 3, metric: VectorMetric::DotProduct)];
        yield 'object to nested' => [Field::object('v', Field::keyword('s')), Field::nested('v', Field::keyword('s'))];
        yield 'boost and analysis together' => [Field::text('title'), Field::text('title', language: 'en', boost: 2.0)];
        yield 'unknown live type' => [Field::unknown('title'), Field::text('title')];
    }

    public function testAnUnknownLiveFieldTheDesiredSchemaDropsIsBreaking(): void
    {
        $plan = MigrationPlan::between(IndexSchema::for('products'), self::live(Field::unknown('percolator')));

        self::assertSame(['percolator'], self::paths($plan->breaking()));
    }

    public function testObjectFieldsAreDiffedByPath(): void
    {
        $plan = MigrationPlan::between(
            IndexSchema::for('products')->nested('variants', Field::keyword('size', filterable: true), Field::integer('stock'), Field::keyword('color')),
            self::live(Field::nested('variants', Field::keyword('size'), Field::integer('stock'))),
        );

        self::assertSame(['variants.color'], self::paths($plan->additive()));
        self::assertSame(['variants.size'], self::paths($plan->breaking()));
    }

    public function testChangesAreOrderedByPath(): void
    {
        $plan = MigrationPlan::between(
            IndexSchema::for('products')->keyword('zeta')->keyword('alpha')->float('mid'),
            self::live(Field::keyword('mid')),
        );

        self::assertSame(['alpha', 'mid', 'zeta'], self::paths($plan->changes));
    }

    public function testThePlanIsAPureFunctionOfItsInputs(): void
    {
        $desired = IndexSchema::for('products')->keyword('a', filterable: true)->text('b', boost: 2.0)->keyword('c');
        $live = self::live(Field::keyword('a'), Field::text('b'), Field::keyword('d'));

        self::assertEquals(MigrationPlan::between($desired, $live), MigrationPlan::between($desired, $live));
    }

    public function testAnAdditivePlanAppliesToTheLiveFields(): void
    {
        $plan = MigrationPlan::between(
            IndexSchema::for('products')->keyword('sku')->keyword('brand')->nested('variants', Field::keyword('size'), Field::keyword('color')),
            self::live(Field::keyword('sku'), Field::nested('variants', Field::keyword('size'))),
        );

        $fields = $plan->applyTo(self::live(Field::keyword('sku'), Field::nested('variants', Field::keyword('size')))->fields);

        self::assertTrue(MigrationPlan::between(IndexSchema::for('products')->keyword('sku')->keyword('brand')->nested('variants', Field::keyword('size'), Field::keyword('color')), new LiveSchema(IndexName::of('products_v1'), $fields))->isEmpty());
    }

    public function testABreakingPlanDoesNotApply(): void
    {
        $plan = MigrationPlan::between(IndexSchema::for('products')->float('sku'), self::live(Field::keyword('sku')));

        $this->expectException(RequiresReindex::class);

        $plan->applyTo([Field::keyword('sku')]);
    }

    private static function live(Field ...$fields): LiveSchema
    {
        return new LiveSchema(IndexName::of('products_v1'), array_values($fields));
    }

    /**
     * @param list<Change> $changes
     * @return list<string>
     */
    private static function paths(array $changes): array
    {
        return array_map(static fn(Change $change): string => $change->path, $changes);
    }
}
