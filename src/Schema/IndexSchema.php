<?php

declare(strict_types=1);

namespace Orion\Schema;

use InvalidArgumentException;
use Orion\IndexName;
use Orion\Support\Assert;
use Orion\Support\Canonical;
use Orion\Support\Input;

use function array_is_list;
use function hash;
use function preg_match;
use function sprintf;

/**
 * The index schema AST (v1 §6): what you diff against the live index. The builder and {@see fromArray()} (the
 * `search:` manifest shape) produce the same AST. Every builder call returns a copy.
 */
final readonly class IndexSchema
{
    /**
     * @param list<Field> $fields
     * @param array<string, array<string, mixed>> $native settings by engine name
     */
    private function __construct(
        public IndexName $index,
        public array $fields = [],
        public ?string $orgKey = null,
        public array $native = [],
    ) {
    }

    public static function for(string $index): self
    {
        return new self(IndexName::of($index));
    }

    /** Engine names as the registry and manifests spell them. */
    private const string ENGINE = '/^[a-z][a-z0-9_-]{0,63}\z/';

    public function with(Field $field): self
    {
        self::assertDeclarable($field);
        $fields = [...$this->fields, $field];
        Field::byName($fields);

        return new self($this->index, $fields, $this->orgKey, $this->native);
    }

    public function text(string $name, ?string $language = null, float $boost = 1.0, bool $folding = false): self
    {
        return $this->with(Field::text($name, $language, $boost, $folding));
    }

    public function keyword(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return $this->with(Field::keyword($name, $filterable, $sortable, $facetable));
    }

    public function integer(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return $this->with(Field::integer($name, $filterable, $sortable, $facetable));
    }

    public function float(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return $this->with(Field::float($name, $filterable, $sortable, $facetable));
    }

    public function date(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return $this->with(Field::date($name, $filterable, $sortable, $facetable));
    }

    public function boolean(string $name, bool $filterable = false, bool $facetable = false): self
    {
        return $this->with(Field::boolean($name, $filterable, $facetable));
    }

    public function geo(string $name, bool $filterable = false, bool $sortable = false): self
    {
        return $this->with(Field::geo($name, $filterable, $sortable));
    }

    public function vector(string $name, int $dims, VectorMetric $metric = VectorMetric::Cosine): self
    {
        return $this->with(Field::vector($name, $dims, $metric));
    }

    public function object(string $name, Field ...$fields): self
    {
        return $this->with(Field::object($name, ...$fields));
    }

    public function nested(string $name, Field ...$fields): self
    {
        return $this->with(Field::nested($name, ...$fields));
    }

    /**
     * The field holding the tenant (v1 §9): a filterable keyword, added when not declared.
     */
    public function orgKey(string $field): self
    {
        $existing = Field::byName($this->fields)[$field] ?? null;
        if ($existing === null) {
            $schema = $this->keyword($field, filterable: true);

            return new self($schema->index, $schema->fields, $field, $schema->native);
        }
        if ($existing->type !== FieldType::Keyword || !$existing->filterable) {
            throw new InvalidArgumentException(sprintf('The org key %s must be a filterable keyword.', Assert::show($field)));
        }

        return new self($this->index, $this->fields, $field, $this->native);
    }

    /**
     * The escape hatch for one engine, as plain data the adapter merges into its native mapping: hashable,
     * diffable and loadable from a manifest, unlike a callback. Replaces earlier settings for that engine.
     * Manifests are developer input; agent-facing tools never construct schemas.
     *
     * @param array<string, mixed> $settings an object, never a list
     */
    public function native(string $engine, array $settings): self
    {
        if (preg_match(self::ENGINE, $engine) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is not an engine name: use lowercase letters, digits, "_" and "-".', Assert::show($engine)));
        }
        if ($settings !== [] && array_is_list($settings)) {
            throw new InvalidArgumentException(sprintf('The native settings for %s must be an object, not a list.', $engine));
        }

        return new self($this->index, $this->fields, $this->orgKey, [...$this->native, $engine => $settings]);
    }

    /**
     * The same declaration for another index: the physical, versioned index behind this alias.
     */
    public function named(IndexName $index): self
    {
        return new self($index, $this->fields, $this->orgKey, $this->native);
    }

    /**
     * Identifies the declaration, whatever order it was written in: equal schemas hash equal. Recorded with the
     * registration and, from WP4, in receipts.
     */
    public function hash(): string
    {
        return hash('sha256', Canonical::json($this->toArray()));
    }

    public function field(string $name): Field
    {
        return Field::byName($this->fields)[$name]
            ?? throw new InvalidArgumentException(sprintf('The schema of %s has no field %s.', Assert::show($this->index->value), Assert::show($name)));
    }

    /**
     * @return array{index: string, fields: array<string, array<string, mixed>>, org_key: string|null, native: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index->value,
            'fields' => Field::mapToArray($this->fields),
            'org_key' => $this->orgKey,
            'native' => $this->native,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        $path = 'schema';
        $data = Input::object($data, $path, ['index'], ['fields', 'org_key', 'native']);
        $schema = self::for(Input::string($data['index'], $path . '.index'));
        foreach (Field::mapFromArray($data['fields'] ?? [], $path . '.fields') as $field) {
            $schema = $schema->with($field);
        }
        if (isset($data['org_key'])) {
            $schema = $schema->orgKey(Input::string($data['org_key'], $path . '.org_key'));
        }
        foreach (Input::map($data['native'] ?? [], $path . '.native') as $engine => $settings) {
            $engine = (string) $engine;
            /** @var array<string, mixed> $settings an object; a numeric-keyed one is refused by native() */
            $settings = Input::map($settings, $path . '.native');
            $schema = $schema->native($engine, $settings);
        }

        return $schema;
    }

    /**
     * Unknown is for adapters reporting what is live; declaring it, at any depth, would plan it as additive.
     */
    private static function assertDeclarable(Field $field): void
    {
        if ($field->type === FieldType::Unknown) {
            throw new InvalidArgumentException(sprintf('The field %s has no neutral type and cannot be declared.', Assert::show($field->name)));
        }
        foreach ($field->fields as $child) {
            self::assertDeclarable($child);
        }
    }
}
