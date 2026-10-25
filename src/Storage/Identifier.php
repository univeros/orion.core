<?php

declare(strict_types=1);

namespace Orion\Storage;

use InvalidArgumentException;
use Orion\Support\Assert;

use function preg_match;
use function sprintf;

/**
 * The one rule for table and column names crossing the {@see Storage} port: lowercase letters, digits and `_`,
 * starting with a letter or `_`, at most 63 bytes (PostgreSQL's limit). Drivers cannot bind identifiers, so every
 * adapter checks them with this before quoting them into SQL.
 */
final class Identifier
{
    private const string PATTERN = '/^[a-z_][a-z0-9_]{0,62}\z/';

    public static function assert(string $identifier): string
    {
        if (preg_match(self::PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is not a valid table or column name: use lowercase letters, digits and "_", at most 63 bytes.', Assert::show($identifier)));
        }

        return $identifier;
    }
}
