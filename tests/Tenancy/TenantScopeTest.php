<?php

declare(strict_types=1);

namespace Orion\Tests\Tenancy;

use InvalidArgumentException;
use Orion\Outbox\Entry;
use Orion\Tenancy\TenantScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

final class TenantScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTenants(): iterable
    {
        yield 'empty' => [''];
        yield 'the separator' => ['org:1'];
        yield 'a control character' => ["org\n1"];
        yield 'not UTF-8' => ["org\xff"];
        yield 'too long' => [str_repeat('o', TenantScope::MAX_BYTES + 1)];
    }

    #[DataProvider('invalidTenants')]
    public function testATenantIsPrintableTextWithoutTheSeparator(string $tenant): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantScope($tenant);
    }

    public function testATenantOwnsTheIdsItQualifies(): void
    {
        $acme = new TenantScope('0199a6f0-7c1e-7b8a-9f00-acme');

        self::assertSame('0199a6f0-7c1e-7b8a-9f00-acme:p1', $acme->qualify('p1'));
        self::assertSame('p1', $acme->unqualify($acme->qualify('p1')));
        self::assertSame('a:b', $acme->unqualify($acme->qualify('a:b')), 'The id may hold the separator: the tenant cannot.');
    }

    public function testAQualifiedIdFitsTheOutboxOrIsRefusedNamingTheApplicationsId(): void
    {
        $tenant = new TenantScope(str_repeat('t', 100));
        $room = Entry::MAX_ID_BYTES - 101;

        self::assertSame(Entry::MAX_ID_BYTES, strlen($tenant->qualify(str_repeat('i', $room))));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most ' . $room . ' bytes');

        $tenant->qualify(str_repeat('i', $room + 1));
    }

    public function testAnIdAnotherTenantQualifiedIsNotThisTenants(): void
    {
        $acme = new TenantScope('acme');
        $globex = new TenantScope('globex');

        self::assertNull($acme->unqualify($globex->qualify('p1')));
        self::assertNull($acme->unqualify('p1'));
        self::assertNull((new TenantScope('acm'))->unqualify($acme->qualify('p1')), 'A prefix of a tenant is another tenant.');
    }
}
