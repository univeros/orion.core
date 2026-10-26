<?php

declare(strict_types=1);

namespace Orion\Tests\Manifest;

use InvalidArgumentException;
use Orion\Manifest\Declaration;
use Orion\Manifest\Loader;
use Orion\Schema\IndexSchema;
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

    public function testSourceAndSearchAreReservedForTheEndpoint(): void
    {
        $this->write('search.yaml', "index: products\nschema:\n  title: { type: text }\nsource: { entity: App\\\\Product }\nsearch: { endpoint: GET /products/search }\n");

        self::assertNotNull((new Loader($this->root))->load()->find('products'));
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
