<?php

declare(strict_types=1);

namespace Orion\Tests\Schema;

use InvalidArgumentException;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

use const NAN;

final class IndexSchemaTest extends TestCase
{
    public function testTheBuilderProducesTheAst(): void
    {
        $schema = self::products();

        self::assertSame('products', $schema->index->value);
        self::assertSame(
            ['title', 'description', 'sku', 'brand', 'price', 'in_stock', 'released', 'location', 'embedding', 'variants', 'org_id'],
            array_map(static fn(Field $field): string => $field->name, $schema->fields),
        );
        self::assertSame('en', $schema->field('title')->language);
        self::assertSame(3.0, $schema->field('title')->boost);
        self::assertTrue($schema->field('sku')->sortable);
        self::assertSame(1536, $schema->field('embedding')->dims);
        self::assertSame(VectorMetric::Cosine, $schema->field('embedding')->metric);
        self::assertSame(FieldType::Nested, $schema->field('variants')->type);
        self::assertSame('org_id', $schema->orgKey);
        self::assertSame(['analyzer' => ['title' => 'edge_ngram_folded']], $schema->native['elasticsearch']);
    }

    public function testTheOrgKeyIsAFilterableKeyword(): void
    {
        $field = self::products()->field('org_id');

        self::assertSame(FieldType::Keyword, $field->type);
        self::assertTrue($field->filterable);
    }

    public function testAnOrgKeyMayNameADeclaredFilterableKeyword(): void
    {
        $schema = IndexSchema::for('products')->keyword('org_id', filterable: true)->orgKey('org_id');

        self::assertCount(1, $schema->fields);
        self::assertSame('org_id', $schema->orgKey);
    }

    public function testAnOrgKeyMayNotNameAnotherKindOfField(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSchema::for('products')->integer('org_id')->orgKey('org_id');
    }

    public function testNativeSettingsAreKeptPerEngineAndReplaced(): void
    {
        $schema = IndexSchema::for('products')
            ->native('elasticsearch', ['a' => 1])
            ->native('solr', ['b' => 2])
            ->native('elasticsearch', ['c' => 3]);

        self::assertSame(['elasticsearch' => ['c' => 3], 'solr' => ['b' => 2]], $schema->native);
    }

    public function testBuildingReturnsACopy(): void
    {
        $base = IndexSchema::for('products');
        $base->keyword('sku');

        self::assertSame([], $base->fields);
    }

    #[DataProvider('schemas')]
    public function testTheAstRoundTripsThroughItsDeclarativeForm(IndexSchema $schema): void
    {
        $data = $schema->toArray();

        self::assertEquals($schema, IndexSchema::fromArray($data));
        self::assertSame($data, IndexSchema::fromArray($data)->toArray());
    }

    /**
     * @return iterable<string, array{IndexSchema}>
     */
    public static function schemas(): iterable
    {
        yield 'empty' => [IndexSchema::for('products')];
        yield 'products' => [self::products()];
        yield 'object' => [IndexSchema::for('people')->object('address', Field::keyword('city', facetable: true), Field::geo('at', filterable: true, sortable: true))];
    }

    public function testTheDeclarativeFormMirrorsTheSearchManifest(): void
    {
        $schema = IndexSchema::for('products')
            ->text('title', language: 'en', boost: 3.0)
            ->keyword('sku', filterable: true, sortable: true)
            ->vector('embedding', dims: 1536, metric: VectorMetric::Cosine)
            ->orgKey('org_id');

        self::assertSame([
            'index' => 'products',
            'fields' => [
                'title' => ['type' => 'text', 'language' => 'en', 'boost' => 3.0],
                'sku' => ['type' => 'keyword', 'filterable' => true, 'sortable' => true],
                'embedding' => ['type' => 'vector', 'dims' => 1536, 'metric' => 'cosine'],
                'org_id' => ['type' => 'keyword', 'filterable' => true],
            ],
            'org_key' => 'org_id',
            'native' => [],
        ], $schema->toArray());
    }

    public function testAnIntegerBoostIsReadAsAFloat(): void
    {
        $schema = IndexSchema::fromArray(['index' => 'products', 'fields' => ['title' => ['type' => 'text', 'boost' => 3]]]);

        self::assertSame(3.0, $schema->field('title')->boost);
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidSchemas')]
    public function testInvalidDeclarativeInputIsRejectedWithItsPath(array $data, string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($path);

        IndexSchema::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidSchemas(): iterable
    {
        yield 'missing index' => [[], 'schema.index'];
        yield 'unknown key' => [['index' => 'products', 'schema' => []], 'schema.schema'];
        yield 'unknown type' => [['index' => 'products', 'fields' => ['a' => ['type' => 'blob']]], 'schema.fields.a.type'];
        yield 'unknown is not declarable' => [['index' => 'products', 'fields' => ['a' => ['type' => 'unknown']]], 'schema.fields.a.type'];
        yield 'option of another type' => [['index' => 'products', 'fields' => ['sku' => ['type' => 'keyword', 'language' => 'en']]], 'schema.fields.sku.language'];
        yield 'vector without dims' => [['index' => 'products', 'fields' => ['e' => ['type' => 'vector']]], 'schema.fields.e.dims'];
        yield 'bad metric' => [['index' => 'products', 'fields' => ['e' => ['type' => 'vector', 'dims' => 3, 'metric' => 'manhattan']]], 'schema.fields.e.metric'];
        yield 'bad nested field' => [['index' => 'products', 'fields' => ['v' => ['type' => 'nested', 'fields' => ['s' => ['type' => 'blob']]]]], 'schema.fields.v.fields.s.type'];
        yield 'fields as a list' => [['index' => 'products', 'fields' => [['type' => 'text']]], 'schema.fields'];
        yield 'non-boolean flag' => [['index' => 'products', 'fields' => ['sku' => ['type' => 'keyword', 'filterable' => 'yes']]], 'schema.fields.sku.filterable'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('hostileSchemas')]
    public function testHostileOrOversizedInputIsRejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSchema::fromArray(['index' => 'products', ...$data]);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function hostileSchemas(): iterable
    {
        yield 'metadata name' => [['fields' => ['_id' => ['type' => 'keyword']]]];
        yield 'newline name' => [['fields' => ["title\n" => ['type' => 'keyword']]]];
        yield 'numeric name' => [['fields' => ['123' => ['type' => 'keyword']]]];
        yield 'bad language' => [['fields' => ['t' => ['type' => 'text', 'language' => 'en; drop']]]];
        yield 'non-finite boost' => [['fields' => ['t' => ['type' => 'text', 'boost' => NAN]]]];
        yield 'oversized dims' => [['fields' => ['e' => ['type' => 'vector', 'dims' => Field::MAX_DIMS + 1]]]];
        yield 'too deep' => [['fields' => ['o' => self::nestedObject(Field::MAX_DEPTH + 1)]]];
        yield 'numeric engine' => [['native' => ['1' => ['a' => 1]]]];
        yield 'list settings' => [['native' => ['elasticsearch' => ['a', 'b']]]];
    }

    public function testNestingUpToTheLimitIsAccepted(): void
    {
        $schema = IndexSchema::fromArray(['index' => 'products', 'fields' => ['o' => self::nestedObject(Field::MAX_DEPTH)]]);

        self::assertCount(1, $schema->fields);
    }

    public function testAnUnknownSubFieldCannotBeDeclared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSchema::for('products')->object('o', Field::keyword('k'), Field::unknown('x'));
    }

    public function testListSettingsAreRefusedByTheBuilderToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        IndexSchema::for('products')->native('elasticsearch', ['a', 'b']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function nestedObject(int $depth): array
    {
        $field = ['type' => 'keyword'];
        for ($i = 1; $i < $depth; ++$i) {
            $field = ['type' => 'object', 'fields' => ['f' => $field]];
        }

        return $field;
    }

    public function testDuplicateFieldsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSchema::for('products')->keyword('sku')->text('sku');
    }

    public function testAnUnknownFieldCannotBeDeclared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSchema::for('products')->with(Field::unknown('mystery'));
    }

    public function testVectorDimensionsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::vector('embedding', dims: 0);
    }

    public function testAnObjectNeedsFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::object('address');
    }

    public function testAnInvalidFieldNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::keyword('has.dot');
    }

    public function testLookingUpAMissingFieldFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IndexSchema::for('products')->field('missing');
    }

    private static function products(): IndexSchema
    {
        return IndexSchema::for('products')
            ->text('title', language: 'en', boost: 3.0)
            ->text('description', language: 'en', folding: true)
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', facetable: true)
            ->float('price', filterable: true, sortable: true, facetable: true)
            ->boolean('in_stock', filterable: true)
            ->date('released', sortable: true)
            ->geo('location', filterable: true)
            ->vector('embedding', dims: 1536, metric: VectorMetric::Cosine)
            ->nested('variants', Field::keyword('size', filterable: true), Field::integer('stock', sortable: true))
            ->orgKey('org_id')
            ->native('elasticsearch', ['analyzer' => ['title' => 'edge_ngram_folded']]);
    }
}
