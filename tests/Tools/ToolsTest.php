<?php

declare(strict_types=1);

namespace Orion\Tests\Tools;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Orion;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FaultyEngine;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableResolver;
use Orion\Tools\Tool;
use Orion\Tools\Tools;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function dirname;
use function in_array;
use function sort;

/**
 * The agent surface of v1 §15, framework-free: each tool is a named, described operation over the graph with a JSON
 * schema for its input, read-only unless it says otherwise, and the mutating ones do nothing without `confirm`.
 */
final class ToolsTest extends TestCase
{
    private const string MANIFESTS = '/fixtures/manifests';
    private const string ENGINE = 'memory';
    private const string INDEX = 'products';

    private FaultyEngine $engine;
    private SettableResolver $resolver;
    private Graph $graph;
    /** @var array<string, Tool> */
    private array $tools;

    protected function setUp(): void
    {
        $this->engine = new FaultyEngine(new InMemoryEngine());
        $this->resolver = new SettableResolver();
        $this->graph = Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(...[self::ENGINE => $this->engine]),
            tenancy: Tenancy::resolvedBy($this->resolver),
            manifestDirectories: [dirname(__DIR__, 3) . '/testing' . self::MANIFESTS],
        ))->graph();
        $this->resolver->tenant = 'acme';
        foreach (Tools::for($this->graph) as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function testEveryToolIsNamedDescribedAndSchemaed(): void
    {
        $names = array_keys($this->tools);
        sort($names);

        self::assertSame(['apply', 'capabilities', 'compile_query', 'describe_index', 'list_indices', 'plan', 'reindex', 'search'], $names);
        self::assertSame($names, Tools::NAMES);
        foreach ($this->tools as $name => $tool) {
            self::assertNotSame('', $tool->description(), $name);
            self::assertSame('object', $tool->inputSchema()['type'] ?? null, $name);
            self::assertSame(in_array($name, ['apply', 'reindex'], true), $tool->mutates(), $name . ' mutates');
        }
    }

    public function testListIndicesReportsTheDeclaredIndicesAndWhetherTheyAreBuilt(): void
    {
        $before = $this->call('list_indices', []);
        $this->build();
        $after = $this->call('list_indices', []);

        self::assertSame(['items', 'products'], array_map(static fn(array $index): string => $index['index'], $before['indices']));
        self::assertSame(['index' => 'products', 'engine' => self::ENGINE, 'built' => false, 'physical' => null, 'version' => null, 'endpoint' => 'GET /products/search'], $before['indices'][1]);
        self::assertSame(['index' => 'products', 'engine' => self::ENGINE, 'built' => true, 'physical' => 'products_v1', 'version' => 1, 'endpoint' => 'GET /products/search'], $after['indices'][1]);
    }

    public function testDescribeIndexShowsTheDeclaredSchemaTheLiveFieldsAndTheCurrentVersion(): void
    {
        $this->build();

        $described = $this->call('describe_index', ['index' => self::INDEX]);

        self::assertSame(self::INDEX, $described['index']);
        self::assertSame(self::ENGINE, $described['engine']);
        self::assertSame($this->graph->manifest()->find(self::INDEX)?->schema->toArray(), $described['declared']);
        self::assertSame([], array_diff(['title', 'brand', 'sku', 'stock', 'embedding'], array_keys($described['live'])), 'The live fields are keyed by name.');
        self::assertSame(['physical' => 'products_v1', 'version' => 1, 'schema_hash' => $this->graph->aliases()->current(IndexName::of(self::INDEX))?->schema->hash()], $described['current']);
        self::assertNull($this->call('describe_index', ['index' => 'items'])['current']);
    }

    public function testAnUndeclaredIndexIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orders');

        $this->call('describe_index', ['index' => 'orders']);
    }

    public function testCapabilitiesListsWhatEachEngineSupports(): void
    {
        $all = $this->call('capabilities', []);
        $one = $this->call('capabilities', ['engine' => self::ENGINE]);

        self::assertSame([self::ENGINE], array_keys($all['engines']));
        self::assertSame($all, $one);
        self::assertTrue($all['engines'][self::ENGINE][Capability::Vectors->name]);
        self::assertFalse($all['engines'][self::ENGINE][Capability::HybridFusion->name]);
        self::assertCount(count(Capability::cases()), $all['engines'][self::ENGINE]);

        $this->expectException(InvalidArgumentException::class);

        $this->call('capabilities', ['engine' => 'nope']);
    }

    public function testCompileQueryShowsWhatTheEngineWouldReceiveWithoutExecuting(): void
    {
        $this->build();
        $this->engine->killBefore(FaultyEngine::SEARCH);

        $compiled = $this->call('compile_query', ['index' => self::INDEX, 'query' => [['type' => 'match', 'field' => 'title', 'value' => 'running']]]);

        self::assertSame(['engine', 'index', 'query', 'native', 'legs', 'fusion'], array_keys($compiled));
        self::assertSame('products_v1', $compiled['index']);
        self::assertSame(2.0, $compiled['query']['query'][0]['boost']);
        self::assertSame([['type' => 'term', 'field' => 'org_id', 'value' => 'acme']], $compiled['native']['filters']);
        self::assertSame(0, $this->engine->calls(FaultyEngine::SEARCH));
    }

    public function testSearchRunsTheScopedQuery(): void
    {
        $this->build();
        $this->seed();

        $results = $this->call('search', ['index' => self::INDEX, 'query' => [['type' => 'match', 'field' => 'title', 'value' => 'running']], 'page' => ['page' => 1, 'size' => 5]]);

        self::assertSame(['hits', 'total', 'total_is_estimate', 'facets', 'took_ms', 'page', 'size'], array_keys($results));
        self::assertSame(['a1'], array_map(static fn(array $hit): string => $hit['id'], $results['hits']));
        self::assertSame('acme', $results['hits'][0]['source']['org_id']);
        self::assertSame(1, $results['total']);
        self::assertSame(['page' => 1, 'size' => 5], ['page' => $results['page'], 'size' => $results['size']]);
    }

    public function testPlanReportsWhereTheDeclarationStands(): void
    {
        $missing = $this->call('plan', ['index' => self::INDEX]);
        $this->build();
        $inSync = $this->call('plan', ['index' => self::INDEX]);

        self::assertSame(['index' => self::INDEX, 'engine' => self::ENGINE, 'state' => 'missing', 'requires_reindex' => false], array_slice($missing, 0, 4));
        self::assertSame('in_sync', $inSync['state']);
        self::assertArrayHasKey('plan', $inSync);
        self::assertSame(0, $this->engine->calls(FaultyEngine::APPLY), 'plan mutates nothing');
    }

    public function testApplyDoesNothingUntilConfirmed(): void
    {
        $preview = $this->call('apply', ['index' => self::INDEX]);
        self::assertSame(['index' => self::INDEX, 'engine' => self::ENGINE, 'dry_run' => true, 'would' => 'create'], array_slice($preview, 0, 4));
        self::assertNull($this->graph->aliases()->current(IndexName::of(self::INDEX)));

        $applied = $this->call('apply', ['index' => self::INDEX, 'confirm' => true]);

        self::assertSame('created', $applied['operation']);
        self::assertSame('products_v1', $applied['physical']);
        self::assertSame('nothing', $this->call('apply', ['index' => self::INDEX])['would']);
    }

    public function testReindexDoesNothingUntilConfirmed(): void
    {
        $this->build();
        $this->seed();

        $preview = $this->call('reindex', ['index' => self::INDEX]);
        self::assertSame(['index' => self::INDEX, 'engine' => self::ENGINE, 'dry_run' => true, 'requires_reindex' => false], array_slice($preview, 0, 4));
        self::assertSame('products_v2', $preview['next']);
        self::assertSame(1, $this->graph->aliases()->current(IndexName::of(self::INDEX))?->version);

        $reindexed = $this->call('reindex', ['index' => self::INDEX, 'confirm' => true, 'batch' => 1]);

        self::assertSame(2, $reindexed['to_version']);
        self::assertSame(3, $reindexed['documents'], 'A reindex replays every tenant.');
        self::assertSame(2, $this->graph->aliases()->current(IndexName::of(self::INDEX))?->version);
    }

    public function testInputIsCheckedBeforeAnythingRuns(): void
    {
        $refused = [
            'an unknown key' => ['plan', ['index' => self::INDEX, 'engine' => self::ENGINE]],
            'a missing index' => ['plan', []],
            'an index that is not a string' => ['describe_index', ['index' => 7]],
            'a confirmation that is not a boolean' => ['apply', ['index' => self::INDEX, 'confirm' => 'yes']],
            'a batch below one' => ['reindex', ['index' => self::INDEX, 'batch' => 0]],
            'a query that is not an object' => ['compile_query', ['index' => self::INDEX, 'query' => 'running']],
        ];
        foreach ($refused as $name => [$tool, $input]) {
            try {
                $this->call($tool, $input);
                self::fail($name . ' was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->engine->calls(FaultyEngine::APPLY));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function call(string $tool, array $input): array
    {
        return $this->tools[$tool]->call($input);
    }

    private function build(): void
    {
        $declared = $this->graph->manifest()->find(self::INDEX);
        self::assertNotNull($declared);
        $this->graph->planner(self::ENGINE)->apply($declared->schema);
    }

    private function seed(): void
    {
        $index = IndexName::of(self::INDEX);
        $this->graph->outbox()->index($index, 'a1', ['title' => 'Trail running shoe', 'brand' => 'acme', 'sku' => 'A-1', 'stock' => 3]);
        $this->graph->outbox()->index($index, 'a2', ['title' => 'Hiking boot', 'brand' => 'acme', 'sku' => 'A-2', 'stock' => 1]);
        $this->resolver->tenant = 'globex';
        $this->graph->outbox()->index($index, 'g1', ['title' => 'Globex running shoe', 'brand' => 'globex', 'sku' => 'G-1', 'stock' => 9]);
        $this->resolver->tenant = 'acme';
        $this->graph->drain()->run($index);
    }
}
