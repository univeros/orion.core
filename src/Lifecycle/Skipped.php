<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use RuntimeException;

/**
 * Rolls a transaction back because a re-check found the work no longer applies (a version became current, or another
 * run already did it). Thrown and caught inside the lifecycle classes; never escapes them.
 *
 * @internal
 */
final class Skipped extends RuntimeException
{
}
