<?php

declare(strict_types=1);

namespace Orion\Storage;

use RuntimeException;

/**
 * What {@see Storage::insert()} throws when a row repeats a primary or unique key: someone else inserted it first.
 * Callers catch this, never a bare driver exception, so a deadlock or a lost connection is not mistaken for a race.
 */
final class DuplicateKey extends RuntimeException
{
}
