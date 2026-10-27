<?php

declare(strict_types=1);

namespace Orion\Manifest;

use InvalidArgumentException;
use Orion\Schema\IndexSchema;
use Orion\Support\Input;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

use function array_keys;
use function array_values;
use function is_dir;
use function ksort;
use function preg_match;
use function sort;
use function sprintf;

/**
 * Reads the `search:` manifests (v1 §12, docs/spec/revision.md §3): every `search.yaml` or `*.search.yaml` (or `.yml`)
 * under the directories, each declaring one index: `index`, `schema` (its fields), and optionally `engine`,
 * `org_key`, `native`, `source` (the entity and projector, {@see Source}) and `search` (the endpoint it serves,
 * {@see Endpoint}, checked against the schema). Unknown keys, invalid schemas, an endpoint route declared twice and an
 * index declared twice are refused with the file named; YAML is parsed without PHP objects or constants. Files are
 * read in path order, so the result is the same on every machine.
 */
final readonly class Loader
{
    private const string FILE = '/(^|\.)search\.ya?ml\z/';
    private const array REQUIRED = ['index', 'schema'];
    private const array OPTIONAL = ['engine', 'org_key', 'native', 'source', 'search'];

    /** @var list<string> */
    private array $directories;

    public function __construct(string ...$directories)
    {
        $this->directories = array_values($directories);
    }

    public function load(): Manifest
    {
        $declarations = [];
        foreach ($this->files() as $file) {
            $declaration = self::declaration($file);
            $index = $declaration->schema->index->value;
            if (isset($declarations[$index])) {
                throw new InvalidArgumentException(sprintf('The index %s is declared twice, in %s and %s.', $index, $declarations[$index]->file, $file));
            }
            $declarations[$index] = $declaration;
        }
        ksort($declarations);
        $routes = [];
        foreach ($declarations as $declaration) {
            $route = $declaration->endpoint === null ? null : $declaration->endpoint->method . ' ' . $declaration->endpoint->path;
            if ($route !== null && isset($routes[$route])) {
                throw new InvalidArgumentException(sprintf('The endpoint %s is declared twice, in %s and %s.', $route, $routes[$route], $declaration->file));
            }
            if ($route !== null) {
                $routes[$route] = $declaration->file;
            }
        }

        return new Manifest($declarations);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];
        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                throw new InvalidArgumentException(sprintf('The manifest directory %s does not exist.', $directory));
            }
            /** @var iterable<SplFileInfo> $entries */
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                if ($entry->isFile() && preg_match(self::FILE, $entry->getFilename()) === 1) {
                    // By real path, so directories that overlap read each file once.
                    $files[(string) ($entry->getRealPath() ?: $entry->getPathname())] = true;
                }
            }
        }
        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * One manifest file, wherever it is.
     *
     * @throws InvalidArgumentException naming the file when it is not a valid declaration
     */
    public static function read(string $file): Declaration
    {
        return self::declaration($file);
    }

    private static function declaration(string $file): Declaration
    {
        try {
            $data = Input::object(Yaml::parseFile($file, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE), 'manifest', self::REQUIRED, self::OPTIONAL);
            Input::map($data['schema'], 'manifest.schema');
            $schema = IndexSchema::fromArray([
                'index' => $data['index'],
                'fields' => $data['schema'],
                'org_key' => $data['org_key'] ?? null,
                'native' => $data['native'] ?? [],
            ]);
            $engine = isset($data['engine']) ? Input::string($data['engine'], 'manifest.engine') : null;
            $source = isset($data['source']) ? Source::fromArray($data['source'], 'manifest.source') : null;
            $endpoint = isset($data['search']) ? Endpoint::fromArray($data['search'], $schema, 'manifest.search') : null;
        } catch (ParseException $invalid) {
            throw new InvalidArgumentException(sprintf('%s is not YAML: %s', $file, $invalid->getMessage()), 0, $invalid);
        } catch (Throwable $invalid) {
            throw new InvalidArgumentException(sprintf('%s: %s', $file, $invalid->getMessage()), 0, $invalid);
        }

        return new Declaration($schema, $engine, $file, $source, $endpoint);
    }
}
