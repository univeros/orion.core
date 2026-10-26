<?php

declare(strict_types=1);

namespace Orion\Tests\Schema;

use Orion\IndexName;
use Orion\Schema\Change;
use Orion\Schema\ChangeKind;
use Orion\Schema\Classification;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * What a live index cannot report (the org key, the per-engine native settings) is diffed against the schema as
 * registered, and every difference fails closed as breaking.
 */
final class PlanAgainstRegisteredSchemaTest extends TestCase
{
    public function testAMatchingRegisteredSchemaPlansNothing(): void
    {
        $schema = self::schema()->orgKey('org_id')->native('elasticsearch', ['settings' => ['index' => ['refresh_interval' => '5s']]]);

        self::assertTrue(MigrationPlan::between($schema, self::live($schema), $schema)->isEmpty());
    }

    public function testAddingAnOrgKeyIsBreaking(): void
    {
        $registered = self::schema();
        $desired = $registered->orgKey('org_id');

        $plan = MigrationPlan::between($desired, self::live($registered), $registered);

        // The key's field itself is a plain additive field; the tenancy change is what needs the reindex.
        self::assertSame(['org_id'], self::paths($plan->additive()));
        self::assertSame([MigrationPlan::ORG_KEY], self::paths($plan->breaking()));
        $change = $plan->breaking()[0];
        self::assertSame(ChangeKind::Added, $change->kind);
        self::assertSame(Classification::Breaking, $change->classification);
        self::assertNull($change->before);
        self::assertSame('org_id', $change->after);
        self::assertTrue($plan->requiresReindex());
    }

    public function testChangingTheOrgKeyIsBreaking(): void
    {
        $registered = self::schema()->keyword('tenant', filterable: true)->orgKey('org_id');
        $desired = self::schema()->keyword('tenant', filterable: true)->keyword('org_id', filterable: true)->orgKey('tenant');

        $change = MigrationPlan::between($desired, self::live($registered), $registered)->breaking()[0];

        self::assertSame(MigrationPlan::ORG_KEY, $change->path);
        self::assertSame(ChangeKind::Changed, $change->kind);
        self::assertSame('org_id', $change->before);
        self::assertSame('tenant', $change->after);
    }

    public function testRemovingTheOrgKeyIsBreaking(): void
    {
        $registered = self::schema()->orgKey('org_id');
        $desired = self::schema()->keyword('org_id', filterable: true);

        $change = MigrationPlan::between($desired, self::live($registered), $registered)->breaking()[0];

        self::assertSame([MigrationPlan::ORG_KEY], self::paths(MigrationPlan::between($desired, self::live($registered), $registered)->changes));
        self::assertSame(ChangeKind::Removed, $change->kind);
        self::assertSame('org_id', $change->before);
        self::assertNull($change->after);
    }

    public function testChangedNativeSettingsAreBreaking(): void
    {
        $registered = self::schema()->native('elasticsearch', ['settings' => ['index' => ['refresh_interval' => '5s']]]);
        $desired = self::schema()->native('elasticsearch', ['settings' => ['index' => ['refresh_interval' => '1s']]]);

        $plan = MigrationPlan::between($desired, self::live($registered), $registered);

        self::assertSame([MigrationPlan::NATIVE . '.elasticsearch'], self::paths($plan->breaking()));
        self::assertSame(ChangeKind::Changed, $plan->changes[0]->kind);
        self::assertSame(['settings' => ['index' => ['refresh_interval' => '5s']]], $plan->changes[0]->before);
        self::assertSame(['settings' => ['index' => ['refresh_interval' => '1s']]], $plan->changes[0]->after);
        self::assertTrue($plan->requiresReindex());
    }

    public function testAddedAndRemovedNativeSettingsAreBreaking(): void
    {
        $registered = self::schema()->native('solr', ['schema' => ['x' => 1]]);
        $desired = self::schema()->native('elasticsearch', ['settings' => ['number_of_shards' => 3]]);

        $plan = MigrationPlan::between($desired, self::live($registered), $registered);

        self::assertSame([MigrationPlan::NATIVE . '.elasticsearch', MigrationPlan::NATIVE . '.solr'], self::paths($plan->breaking()));
        self::assertSame([ChangeKind::Added, ChangeKind::Removed], array_map(static fn(Change $c): ChangeKind => $c->kind, $plan->changes));
        self::assertSame([], $plan->additive());
    }

    public function testNativeKeyOrderIsNotDrift(): void
    {
        $registered = self::schema()->native('elasticsearch', ['settings' => ['a' => 1, 'b' => 2]]);
        $desired = self::schema()->native('elasticsearch', ['settings' => ['b' => 2, 'a' => 1]]);

        self::assertTrue(MigrationPlan::between($desired, self::live($registered), $registered)->isEmpty());
    }

    public function testEmptyNativeSettingsEqualNone(): void
    {
        $registered = self::schema();
        $desired = self::schema()->native('elasticsearch', []);

        self::assertTrue(MigrationPlan::between($desired, self::live($registered), $registered)->isEmpty());
    }

    public function testALiveIndexWithNoRegistrationFailsClosedOnWhatItCannotReport(): void
    {
        $desired = self::schema()->orgKey('org_id')->native('elasticsearch', ['settings' => ['a' => 1]]);

        $plan = MigrationPlan::between($desired, self::live(self::schema()), null);

        self::assertSame(['org_id'], self::paths($plan->additive()));
        self::assertSame([MigrationPlan::NATIVE . '.elasticsearch', MigrationPlan::ORG_KEY], self::paths($plan->breaking()));
        self::assertNull($plan->breaking()[0]->before);
        self::assertNull($plan->breaking()[1]->before);
    }

    public function testCreatingAnIndexPlansNothingBreaking(): void
    {
        $desired = self::schema()->orgKey('org_id')->native('elasticsearch', ['settings' => ['a' => 1]]);

        $plan = MigrationPlan::between($desired, null, null);

        self::assertSame(['org_id', 'sku', 'title'], self::paths($plan->additive()));
        self::assertSame([], $plan->breaking());
    }

    public function testADroppedPhysicalIndexIsStillDiffedAgainstItsRegistration(): void
    {
        $registered = self::schema()->orgKey('org_id');
        $desired = self::schema()->orgKey('tenant');

        $plan = MigrationPlan::between($desired, null, $registered);

        self::assertSame(['sku', 'tenant', 'title'], self::paths($plan->additive()));
        self::assertSame([MigrationPlan::ORG_KEY], self::paths($plan->breaking()));
    }

    public function testSettingChangesSortWithTheFieldChanges(): void
    {
        $registered = self::schema()->orgKey('org_id');
        $desired = self::schema()->keyword('zzz')->keyword('aaa');

        $plan = MigrationPlan::between($desired, self::live($registered), $registered);

        self::assertSame([MigrationPlan::ORG_KEY, 'aaa', 'org_id', 'zzz'], self::paths($plan->changes));
    }

    public function testThePlanHasADeclarativeForm(): void
    {
        $registered = self::schema()->orgKey('org_id');
        $desired = IndexSchema::for('products')->text('title', language: 'en')->keyword('brand', facetable: true)->float('sku')->native('elasticsearch', ['settings' => ['a' => 1]]);

        $plan = MigrationPlan::between($desired, self::live($registered), $registered);

        self::assertSame([
            'index' => 'products',
            'changes' => [
                ['path' => MigrationPlan::NATIVE . '.elasticsearch', 'kind' => 'added', 'classification' => 'breaking', 'before' => null, 'after' => ['settings' => ['a' => 1]]],
                ['path' => MigrationPlan::ORG_KEY, 'kind' => 'removed', 'classification' => 'breaking', 'before' => 'org_id', 'after' => null],
                ['path' => 'brand', 'kind' => 'added', 'classification' => 'additive', 'before' => null, 'after' => ['type' => 'keyword', 'facetable' => true]],
                ['path' => 'org_id', 'kind' => 'removed', 'classification' => 'breaking', 'before' => ['type' => 'keyword', 'filterable' => true], 'after' => null],
                ['path' => 'sku', 'kind' => 'changed', 'classification' => 'breaking', 'before' => ['type' => 'keyword', 'filterable' => true], 'after' => ['type' => 'float']],
            ],
        ], $plan->toArray());
        self::assertSame($plan->toArray(), MigrationPlan::between($desired, self::live($registered), $registered)->toArray());
    }

    public function testTheSchemaHashIdentifiesTheDeclarationWhateverItsOrder(): void
    {
        $a = IndexSchema::for('products')->keyword('a')->text('b', boost: 2.0)->native('elasticsearch', ['settings' => ['x' => 1, 'y' => 2]])->orgKey('org_id');
        $b = IndexSchema::for('products')->orgKey('org_id')->text('b', boost: 2.0)->keyword('a')->native('elasticsearch', ['settings' => ['y' => 2, 'x' => 1]]);

        self::assertSame($a->hash(), $b->hash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}\z/', $a->hash());
        self::assertNotSame($a->hash(), $a->text('c')->hash());
        self::assertNotSame($a->hash(), IndexSchema::for('products')->keyword('a')->text('b', boost: 3.0)->native('elasticsearch', ['settings' => ['x' => 1, 'y' => 2]])->orgKey('org_id')->hash(), 'The hash identifies the declaration; a boost is part of it.');
        self::assertNotSame($a->hash(), $a->named(IndexName::of('products_v1'))->hash());
    }

    public function testASchemaCanBeRenamedToItsPhysicalIndex(): void
    {
        $schema = self::schema()->orgKey('org_id')->native('elasticsearch', ['settings' => ['x' => 1]]);

        $physical = $schema->named(IndexName::of('products_v7'));

        self::assertSame('products_v7', $physical->index->value);
        self::assertSame([...$schema->toArray(), 'index' => 'products_v7'], $physical->toArray());
        self::assertSame('products', $schema->index->value);
    }

    private static function schema(): IndexSchema
    {
        return IndexSchema::for('products')->text('title', language: 'en')->keyword('sku', filterable: true);
    }

    private static function live(IndexSchema $schema): LiveSchema
    {
        return new LiveSchema(IndexName::of('products_v1'), $schema->fields);
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
