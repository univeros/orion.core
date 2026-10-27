<?php

declare(strict_types=1);

namespace Orion\Tests\Manifest;

use InvalidArgumentException;
use Orion\Manifest\Declaration;
use Orion\Manifest\Endpoint;
use Orion\Manifest\Loader;
use Orion\Schema\IndexSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class LoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/orion-manifest-' . uniqid();
        mkdir($this->root);
    }

    public function testSearchManifestsDeclareIndicesWithTheirSchema(): void
    {
        $this->write('products/search.yaml', <<<'YAML'
            index: products
            engine: elasticsearch
            schema:
              title: { type: text, language: en, boost: 3.0 }
              sku: { type: keyword, filterable: true }
            org_key: org_id
            YAML);
        $this->write('orders.search.yml', "index: orders\nschema:\n  status: { type: keyword }\n");
        $this->write('products/endpoints.yaml', "paths: {}\n");

        $manifest = (new Loader($this->root))->load();

        self::assertSame(['orders', 'products'], array_map(static fn(Declaration $d): string => $d->schema->index->value, $manifest->declarations()));
        $products = $manifest->find('products');
        self::assertSame('elasticsearch', $products?->engine);
        self::assertSame(IndexSchema::for('products')->text('title', language: 'en', boost: 3.0)->keyword('sku', filterable: true)->orgKey('org_id')->hash(), $products->schema->hash());
        self::assertNull($manifest->find('orders')?->engine);
        self::assertNull($manifest->find('customers'));
    }

    public function testSourceNamesTheEntityAndProjector(): void
    {
        $this->write('search.yaml', "index: products\nschema:\n  title: { type: text }\nsource: { entity: App\\Product, projector: App\\Search\\ProductProjector }\n");

        $source = (new Loader($this->root))->load()->find('products')?->source;

        self::assertSame(['entity' => 'App\Product', 'projector' => 'App\Search\ProductProjector'], $source?->toArray());
    }

    public function testSearchDeclaresTheEndpointCheckedAgainstTheSchema(): void
    {
        $this->write('products/search.yaml', <<<'YAML'
            index: products
            schema:
              title: { type: text, language: en, boost: 3.0 }
              description: { type: text }
              brand: { type: keyword, filterable: true, facetable: true }
              price: { type: float, filterable: true, sortable: true }
              embedding: { type: vector, dims: 3 }
            search:
              endpoint: POST /products/search
              permission: products.search
              query_fields: { title: 2.0, description: 1.0 }
              hybrid: { vector_field: embedding, fusion: reciprocal_rank }
              filterable: [brand, price]
              sortable: [price, _score]
              facets: [brand]
              highlight: [title]
              default_sort: -_score
              page_size: 50
            YAML);

        $endpoint = (new Loader($this->root))->load()->find('products')?->endpoint;

        self::assertNotNull($endpoint);
        self::assertSame(['POST', '/products/search', 'products.search'], [$endpoint->method, $endpoint->path, $endpoint->permission]);
        self::assertSame(['title' => 2.0, 'description' => 1.0], $endpoint->queryFields);
        self::assertSame('embedding', $endpoint->vectorField);
        self::assertSame(['strategy' => 'reciprocal_rank', 'k' => 60], $endpoint->fusion?->toArray());
        self::assertSame([['brand', 'price'], ['price', '_score'], ['brand'], ['title']], [$endpoint->filterable, $endpoint->sortable, $endpoint->facets, $endpoint->highlight]);
        self::assertSame(['_score', 'desc'], [$endpoint->defaultSort?->field, $endpoint->defaultSort?->direction->value]);
        self::assertSame(50, $endpoint->pageSize);
        $schema = (new Loader($this->root))->load()->find('products')?->schema;
        self::assertNotNull($schema);
        self::assertSame($endpoint->toArray(), Endpoint::fromArray($endpoint->toArray(), $schema, 'x')->toArray());
    }

    public function testTheMinimalEndpointIsARouteAndItsQueryFields(): void
    {
        $this->write('search.yaml', "index: products\nschema:\n  title: { type: text }\nsearch: { endpoint: GET /products/search, query_fields: [title] }\n");

        $endpoint = (new Loader($this->root))->load()->find('products')?->endpoint;

        self::assertSame(['GET', '/products/search', ['title' => 1.0], null, [], null, 20, null], [$endpoint?->method, $endpoint?->path, $endpoint?->queryFields, $endpoint?->vectorField, $endpoint?->facets, $endpoint?->defaultSort, $endpoint?->pageSize, $endpoint?->permission]);
        self::assertCount(1, (new Loader($this->root))->load()->endpoints());
    }

    #[DataProvider('misdeclaredEndpoints')]
    public function testAnEndpointThatDoesNotFitTheSchemaIsRefusedWithTheFileNamed(string $search, string $message): void
    {
        $this->write('search.yaml', "index: products\nschema:\n  title: { type: text }\n  sku: { type: keyword, filterable: true }\n  price: { type: float, sortable: true }\n  embedding: { type: vector, dims: 3 }\nsearch:\n" . $search);

        try {
            (new Loader($this->root))->load();
            self::fail('loaded');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString($this->root . '/search.yaml', $refused->getMessage());
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misdeclaredEndpoints(): iterable
    {
        yield 'no route' => ["  query_fields: [title]\n", 'manifest.search.endpoint is required'];
        yield 'bad route' => ["  endpoint: products/search\n  query_fields: [title]\n", 'manifest.search.endpoint must be "METHOD /path"'];
        yield 'bad method' => ["  endpoint: PUT /products/search\n  query_fields: [title]\n", 'served by GET or POST, not "PUT"'];
        yield 'path with a parameter' => ["  endpoint: 'GET /products/{id}'\n  query_fields: [title]\n", 'is not a plain path'];
        yield 'no query field' => ["  endpoint: GET /products/search\n  query_fields: []\n", 'manifest.search.query_fields must be a non-empty list'];
        yield 'undeclared query field' => ["  endpoint: GET /products/search\n  query_fields: [name]\n", 'manifest.search.query_fields names "name", which the schema does not declare'];
        yield 'a vector as query field' => ["  endpoint: GET /products/search\n  query_fields: [embedding]\n", 'is not a text or keyword field'];
        yield 'unfilterable' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  filterable: [title]\n", 'manifest.search.filterable names "title", which is not filterable'];
        yield 'unsortable' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  sortable: [sku]\n", 'manifest.search.sortable names "sku", which is not sortable'];
        yield 'unfacetable' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  facets: [sku]\n", 'manifest.search.facets names "sku", which is not facetable'];
        yield 'highlight a keyword' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  highlight: [sku]\n", 'manifest.search.highlight names "sku", which is not a text field'];
        yield 'default sort outside sortable' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  default_sort: sku\n", 'The default sort "sku" is not among the sortable fields'];
        yield 'hybrid on GET' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  hybrid: { vector_field: embedding }\n", 'serve it with POST'];
        yield 'hybrid on a keyword' => ["  endpoint: POST /products/search\n  query_fields: [title]\n  hybrid: { vector_field: sku }\n", 'manifest.search.hybrid.vector_field names "sku", which is not a vector field'];
        yield 'weighted fusion' => ["  endpoint: POST /products/search\n  query_fields: [title]\n  hybrid: { vector_field: embedding, fusion: { strategy: weighted, lexical: 0.5, vector: 0.5 } }\n", 'Core fuses by reciprocal rank only'];
        yield 'hybrid with a field default sort' => ["  endpoint: POST /products/search\n  query_fields: [title]\n  hybrid: { vector_field: embedding }\n  sortable: [price]\n  default_sort: -price\n", 'A hybrid endpoint ranks by fusion'];
        yield 'zero weight' => ["  endpoint: GET /products/search\n  query_fields: { title: 0 }\n", 'manifest.search.query_fields.title must be a positive weight'];
        yield 'bad field name in the list' => ["  endpoint: GET /products/search\n  query_fields: ['*']\n", 'In manifest.search.query_fields, the field "*" is invalid'];
        yield 'unknown fusion' => ["  endpoint: POST /products/search\n  query_fields: [title]\n  hybrid: { vector_field: embedding, fusion: borda }\n", 'manifest.search.hybrid.fusion.strategy'];
        yield 'oversized page' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  page_size: 1001\n", 'The page size must be at most 1000'];
        yield 'bad permission' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  permission: 'read all'\n", 'is not a permission key'];
        yield 'unknown key' => ["  endpoint: GET /products/search\n  query_fields: [title]\n  boost: 2\n", 'manifest.search.boost is not a known key'];
        yield 'bad source class' => ["  endpoint: GET /products/search\n  query_fields: [title]\nsource: { entity: 'App Product' }\n", 'The source entity "App Product" is not a class name'];
    }

    public function testARouteDeclaredTwiceIsRefused(): void
    {
        $this->write('a.search.yaml', "index: a\nschema:\n  title: { type: text }\nsearch: { endpoint: GET /search, query_fields: [title] }\n");
        $this->write('b.search.yaml', "index: b\nschema:\n  title: { type: text }\nsearch: { endpoint: GET /search, query_fields: [title] }\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The endpoint GET /search is declared twice');

        (new Loader($this->root))->load();
    }

    public function testAnUnknownKeyIsRefusedWithTheFileNamed(): void
    {
        $this->write('search.yaml', "index: products\nschema:\n  title: { type: text }\nshema: {}\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('search.yaml');

        (new Loader($this->root))->load();
    }

    public function testAnInvalidSchemaIsRefusedWithTheFileNamed(): void
    {
        $this->write('search.yaml', "index: products\nschema:\n  title: { type: txt }\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('search.yaml');

        (new Loader($this->root))->load();
    }

    public function testAnIndexDeclaredTwiceIsRefused(): void
    {
        $this->write('a/search.yaml', "index: products\nschema:\n  title: { type: text }\n");
        $this->write('b/search.yaml', "index: products\nschema:\n  title: { type: text }\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declared twice');

        (new Loader($this->root))->load();
    }

    public function testOverlappingDirectoriesReadEachFileOnce(): void
    {
        $this->write('products/search.yaml', "index: products\nschema:\n  title: { type: text }\n");

        self::assertCount(1, (new Loader($this->root, $this->root . '/products'))->load()->declarations());
    }

    public function testAMissingDirectoryIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Loader($this->root . '/missing'))->load();
    }

    public function testPhpObjectsInYamlAreRefused(): void
    {
        $this->write('search.yaml', "index: products\nschema: !php/object 'O:8:\"stdClass\":0:{}'\n");

        $this->expectException(InvalidArgumentException::class);

        (new Loader($this->root))->load();
    }

    private function write(string $path, string $contents): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $contents);
    }
}
