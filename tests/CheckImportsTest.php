<?php

declare(strict_types=1);

namespace Orion\Tests;

use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * `bin/check-imports` is the dependency-isolation gate (docs/spec/revision.md §2): the repository
 * passes it, and a framework namespace leaking into core fails it.
 */
final class CheckImportsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/check-imports';

    /** Assembled at runtime so this file does not itself trip the gate. */
    private const string FRAMEWORK_CLASS = 'Altair' . '\\Container' . '\\Container';

    public function testTheRepositoryPasses(): void
    {
        [$status, $output] = self::check(dirname(__DIR__, 3));

        self::assertSame(0, $status, $output);
    }

    public function testAFrameworkNamespaceInCoreFails(): void
    {
        $root = sys_get_temp_dir() . '/orion-imports-' . uniqid();
        mkdir($root . '/packages/core/src', 0o777, true);
        file_put_contents($root . '/packages/core/src/Leak.php', "<?php\nuse " . self::FRAMEWORK_CLASS . ";\n");

        [$status, $output] = self::check($root);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('packages/core/src/Leak.php', $output);
    }

    public function testAFrameworkNamespaceInItsOwnPackagePasses(): void
    {
        $root = sys_get_temp_dir() . '/orion-imports-' . uniqid();
        mkdir($root . '/packages/univeros/src', 0o777, true);
        file_put_contents($root . '/packages/univeros/src/Module.php', "<?php\nuse " . self::FRAMEWORK_CLASS . ";\n");

        [$status] = self::check($root);

        self::assertSame(0, $status);
    }

    public function testTheConsoleStaysInTheCliAndHostPackages(): void
    {
        $root = sys_get_temp_dir() . '/orion-imports-' . uniqid();
        $console = 'Symfony' . '\\Component' . '\\Console' . '\\Command' . '\\Command';
        mkdir($root . '/packages/core/src', 0o777, true);
        mkdir($root . '/packages/cli/src', 0o777, true);
        file_put_contents($root . '/packages/cli/src/PlanCommand.php', "<?php\nuse " . $console . ";\n");
        self::assertSame(0, self::check($root)[0]);

        file_put_contents($root . '/packages/core/src/Leak.php', "<?php\nuse " . $console . ";\n");
        [$status, $output] = self::check($root);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('packages/core/src/Leak.php', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function check(string $root): array
    {
        $lines = [];
        $status = 0;
        exec(escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($root) . ' 2>&1', $lines, $status);

        return [$status, implode("\n", $lines)];
    }
}
