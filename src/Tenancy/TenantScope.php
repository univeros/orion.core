<?php

declare(strict_types=1);

namespace Orion\Tenancy;

use InvalidArgumentException;
use Orion\Outbox\Entry;
use Orion\Support\Assert;

use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The tenant an operation is scoped to (v1 §9), such as a Polaris organization. On a shared index the tenant owns
 * the engine ids it {@see qualify()}s, so two tenants' documents never share an id and one cannot overwrite or
 * delete the other's.
 */
final readonly class TenantScope
{
    public const int MAX_BYTES = 128;
    public const string SEPARATOR = ':';

    public function __construct(public string $tenant)
    {
        Assert::notEmpty($tenant, 'A tenant');
        if (preg_match('/^[^:\x00-\x1F\x7F]+\z/u', $tenant) !== 1 || strlen($tenant) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf('A tenant is printable UTF-8 of at most %d bytes without "%s", %s is not.', self::MAX_BYTES, self::SEPARATOR, Assert::show($tenant)));
        }
    }

    /**
     * The engine id of this tenant's document. It must fit the outbox's id column, so the application's id has that
     * much less room under a tenant.
     *
     * @throws InvalidArgumentException when the qualified id would not fit
     */
    public function qualify(string $id): string
    {
        $room = Entry::MAX_ID_BYTES - strlen($this->tenant) - strlen(self::SEPARATOR);
        if (strlen($id) > $room) {
            throw new InvalidArgumentException(sprintf('Under the tenant %s a document id is at most %d bytes, %s is longer.', Assert::show($this->tenant), $room, Assert::show($id)));
        }

        return $this->tenant . self::SEPARATOR . $id;
    }

    /**
     * The document id inside an engine id this tenant qualified, or null when the engine id is not this tenant's.
     */
    public function unqualify(string $engineId): ?string
    {
        $prefix = $this->tenant . self::SEPARATOR;

        return str_starts_with($engineId, $prefix) ? substr($engineId, strlen($prefix)) : null;
    }
}
