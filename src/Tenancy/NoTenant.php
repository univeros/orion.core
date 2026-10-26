<?php

declare(strict_types=1);

namespace Orion\Tenancy;

use RuntimeException;

/**
 * A resolver found no tenant in the current context: nothing is searched or recorded. An endpoint answers it as
 * unauthorized, never by falling back to some other scope.
 */
final class NoTenant extends RuntimeException
{
}
