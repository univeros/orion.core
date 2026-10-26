<?php

declare(strict_types=1);

namespace Orion\Tests\Tenancy;

use Orion\Adapter\SearchAdapter;
use Orion\Query\Query;
use Orion\Query\Searcher;
use Orion\Tenancy\IndexScope;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;

use function class_exists;
use function dirname;
use function file_get_contents;
use function glob;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;
use function is_dir;
use function json_decode;
use function sort;
use function str_replace;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Tenancy holds only if every way Orion queries an engine goes through the {@see Searcher}, which injects the scope
 * (v1 §9). This reads every public method of every Orion package and fails on any new one that takes a query or a
 * native escape-hatch callable, until it is reviewed and listed here.
 */
final class NoUnscopedQueryPathTest extends TestCase
{
    /**
     * Where a query may go: the searcher, which scopes it through the index scope; the engine adapters it then calls;
     * Elasticsearch's compiler, which turns a query into native DSL and executes nothing (named in pieces so this file
     * does not trip bin/check-imports).
     */
    private const array REVIEWED = [Searcher::class, IndexScope::class, 'Orion\\Elastic' . 'search\\QueryCompiler'];

    public function testOnlyTheSearcherHandsAQueryToAnEngine(): void
    {
        $unreviewed = [];
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            if (in_array($class, self::REVIEWED, true) || is_a($class, SearchAdapter::class, true)) {
                continue;
            }
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $class && self::takesAQuery($method)) {
                    $unreviewed[] = $class . '::' . $method->getName();
                }
            }
        }
        sort($unreviewed);

        self::assertSame([], $unreviewed, 'These accept a query outside the scoped searcher.');
    }

    public function testTheReviewedPathsStillExist(): void
    {
        self::assertTrue(self::takesAQuery(new ReflectionMethod(Searcher::class, 'search')));
        self::assertTrue(self::takesAQuery(new ReflectionMethod(Searcher::class, 'raw')));
        self::assertTrue(self::takesAQuery(new ReflectionMethod(SearchAdapter::class, 'search')));
    }

    private static function takesAQuery(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($parameter->getName() === 'native' || ($type instanceof ReflectionNamedType && $type->getName() === Query::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class of every package, found through each package's PSR-4 roots, so a new package is read too.
     *
     * @return list<class-string>
     */
    private function classes(): array
    {
        $classes = [];
        foreach ((array) glob(dirname(__DIR__, 3) . '/*/composer.json') as $manifest) {
            $package = dirname((string) $manifest);
            $composer = json_decode((string) file_get_contents((string) $manifest), true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, string> $roots */
            $roots = is_array($composer) ? ($composer['autoload']['psr-4'] ?? []) : [];
            foreach ($roots as $namespace => $directory) {
                $root = $package . '/' . $directory;
                if (!is_dir($root)) {
                    continue;
                }
                /** @var SplFileInfo $file */
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                    $class = $namespace . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
                    if ($file->getExtension() === 'php' && (class_exists($class) || interface_exists($class))) {
                        $classes[] = $class;
                    }
                }
            }
        }

        return $classes;
    }
}
