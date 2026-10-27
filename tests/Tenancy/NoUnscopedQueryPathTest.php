<?php

declare(strict_types=1);

namespace Orion\Tests\Tenancy;

use Orion\Adapter\QueryCompiling;
use Orion\Adapter\SearchAdapter;
use Orion\Query\Binder;
use Orion\Query\Compilation;
use Orion\Query\Query;
use Orion\Query\Searcher;
use Orion\Tenancy\IndexScope;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
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
     * Where a query may go: the searcher, which binds it to the schema (the binder, which executes nothing) and
     * scopes it through the index scope; the engine adapters it then calls;
     * each engine's compiler, which turns a query into native parameters and executes nothing (named in pieces so this
     * file does not trip bin/check-imports); Solr's `NativeScope`, which restricts a native request to the scope;
     * `QueryCompiling`, the engines' compile-only port the searcher calls on the scoped query; `Compilation`, the
     * value that carries the scoped query and its native form back, executing nothing.
     */
    private const array REVIEWED = [
        Searcher::class,
        IndexScope::class,
        Binder::class,
        QueryCompiling::class,
        Compilation::class,
        'Orion\\Elastic' . 'search\\QueryCompiler',
        'Orion\\Open' . 'Search\\QueryCompiler',
        'Orion\\Solr\\QueryCompiler',
        'Orion\\Solr\\NativeScope',
    ];

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

    public function testAQueryInAUnionOrNullableTypeCounts(): void
    {
        self::assertTrue(self::takesAQuery(new ReflectionFunction(static fn(Query|array $query): null => null)));
        self::assertTrue(self::takesAQuery(new ReflectionFunction(static fn(?Query $query): null => null)));
        self::assertFalse(self::takesAQuery(new ReflectionFunction(static fn(string $index): null => null)));
    }

    private static function takesAQuery(ReflectionFunctionAbstract $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $named = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : [$type];
            foreach ($named as $one) {
                if ($one instanceof ReflectionNamedType && $one->getName() === Query::class) {
                    return true;
                }
            }
            if ($parameter->getName() === 'native') {
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
