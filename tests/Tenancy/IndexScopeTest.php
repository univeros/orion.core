<?php

declare(strict_types=1);

namespace Orion\Tests\Tenancy;

use InvalidArgumentException;
use LogicException;
use Orion\Query\Contribution;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\FusionTrace;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Tenancy\IndexScope;
use Orion\Tenancy\TenantScope;
use PHPUnit\Framework\TestCase;

final class IndexScopeTest extends TestCase
{
    public function testUnscopedChangesNothing(): void
    {
        $scope = IndexScope::unscoped();
        $query = Query::on('products')->match('title', 'shoe');
        $results = new ResultSet([new Hit('p1', 1.0, ['title' => 'shoe'], [])], 1, false, [], 1.0);

        self::assertSame([], $scope->filters());
        self::assertSame($query, $scope->query($query));
        self::assertSame('p1', $scope->id('p1'));
        self::assertSame(['title' => 'shoe'], $scope->fields(['title' => 'shoe']));
        self::assertSame($results, $scope->results($results));
    }

    public function testATenantScopeFiltersOnTheOrgKeyBeneathWhateverTheQueryFilters(): void
    {
        $scope = IndexScope::of(new TenantScope('acme'), 'org_id');
        $query = Query::on('products')->filter(Filter::bool(should: [Filter::term('org_id', 'globex')]));

        self::assertEquals([Filter::term('org_id', 'acme')], $scope->filters());
        self::assertEquals([...$query->filters, Filter::term('org_id', 'acme')], $scope->query($query)->filters);
    }

    public function testATenantsWritesAreQualifiedAndStampedWithTheOrgKey(): void
    {
        $scope = IndexScope::of(new TenantScope('acme'), 'org_id');

        self::assertSame('acme:p1', $scope->id('p1'));
        self::assertSame(['title' => 'shoe', 'org_id' => 'acme'], $scope->fields(['title' => 'shoe']));
        self::assertSame(['org_id' => 'acme', 'title' => 'shoe'], $scope->fields(['org_id' => 'acme', 'title' => 'shoe']));
    }

    public function testADocumentProjectedForAnotherTenantIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexScope::of(new TenantScope('acme'), 'org_id')->fields(['org_id' => 'globex']);
    }

    public function testHitsComeBackWithTheApplicationsIds(): void
    {
        $scope = IndexScope::of(new TenantScope('acme'), 'org_id');
        $trace = new FusionTrace(Fusion::reciprocalRank(), [new Contribution('acme:p1', 'lexical', 1, 0.5)]);

        $results = $scope->results(new ResultSet([new Hit('acme:p1', 2.0, ['org_id' => 'acme'], ['title' => ['<em>x</em>']])], 1, false, ['org_id' => ['acme' => 1]], 3.0, $trace));

        self::assertEquals(new ResultSet([new Hit('p1', 2.0, ['org_id' => 'acme'], ['title' => ['<em>x</em>']])], 1, false, ['org_id' => ['acme' => 1]], 3.0, new FusionTrace(Fusion::reciprocalRank(), [new Contribution('p1', 'lexical', 1, 0.5)])), $results);
    }

    public function testAHitOutsideTheTenantNeverReachesTheCaller(): void
    {
        $scope = IndexScope::of(new TenantScope('acme'), 'org_id');

        $this->expectException(LogicException::class);

        $scope->results(new ResultSet([new Hit('acme:p1', 1.0, [], []), new Hit('globex:p1', 1.0, [], [])], 2, false, [], 1.0));
    }
}
