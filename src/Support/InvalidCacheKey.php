<?php

declare(strict_types=1);

namespace Orion\Support;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Thrown by {@see InMemoryCache} for an empty key or one carrying a character PSR-16 reserves (`{}()/\@:`).
 */
final class InvalidCacheKey extends InvalidArgumentException implements PsrInvalidArgumentException
{
}
