<?php

declare(strict_types=1);

namespace Orion\Schema;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function array_values;
use function count;
use function preg_match;
use function sprintf;

/**
 * One field of an index schema. Analysis is a neutral language hint and folding only; anything past that is
 * `IndexSchema::native()`. Immutable.
 */
final readonly class Field
{
    /** The highest dimension count the engines index (Elasticsearch, OpenSearch). */
    public const int MAX_DIMS = 4096;
    /** How deep object and nested fields may nest. */
    public const int MAX_DEPTH = 10;
    /** An ISO 639 code with an optional subtag: `en`, `pt-br`. */
    private const string LANGUAGE = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?\z/';
    private const array FLAGS = ['filterable', 'sortable', 'facetable'];

    /** Required and optional declarative keys per type, beside `type`. */
    private const array KEYS = [
        'text' => [[], ['language', 'boost', 'folding']],
        'keyword' => [[], self::FLAGS],
        'integer' => [[], self::FLAGS],
        'float' => [[], self::FLAGS],
        'date' => [[], self::FLAGS],
        'boolean' => [[], ['filterable', 'facetable']],
        'geo' => [[], ['filterable', 'sortable']],
        'vector' => [['dims'], ['metric']],
        'object' => [['fields'], []],
        'nested' => [['fields'], []],
    ];

    /**
     * @param list<Field> $fields
     */
    private function __construct(
        public string $name,
        public FieldType $type,
        public ?string $language = null,
        public float $boost = 1.0,
        public bool $folding = false,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $facetable = false,
        public ?int $dims = null,
        public ?VectorMetric $metric = null,
        public array $fields = [],
    ) {
        Assert::fieldName($name, 'The field name');
    }

    public static function text(string $name, ?string $language = null, float $boost = 1.0, bool $folding = false): self
    {
        if ($language !== null && preg_match(self::LANGUAGE, $language) !== 1) {
            throw new InvalidArgumentException(sprintf('The language %s of %s is not a language code such as "en" or "pt-br".', Assert::show($language), Assert::show($name)));
        }
        if (Assert::finite($boost, 'A boost') <= 0) {
            throw new InvalidArgumentException(sprintf('The boost of %s must be positive.', Assert::show($name)));
        }

        return new self($name, FieldType::Text, language: $language, boost: $boost, folding: $folding);
    }

    public static function keyword(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return new self($name, FieldType::Keyword, filterable: $filterable, sortable: $sortable, facetable: $facetable);
    }

    public static function integer(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return new self($name, FieldType::Integer, filterable: $filterable, sortable: $sortable, facetable: $facetable);
    }

    public static function float(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return new self($name, FieldType::Float, filterable: $filterable, sortable: $sortable, facetable: $facetable);
    }

    public static function date(string $name, bool $filterable = false, bool $sortable = false, bool $facetable = false): self
    {
        return new self($name, FieldType::Date, filterable: $filterable, sortable: $sortable, facetable: $facetable);
    }

    public static function boolean(string $name, bool $filterable = false, bool $facetable = false): self
    {
        return new self($name, FieldType::Boolean, filterable: $filterable, facetable: $facetable);
    }

    public static function geo(string $name, bool $filterable = false, bool $sortable = false): self
    {
        return new self($name, FieldType::Geo, filterable: $filterable, sortable: $sortable);
    }

    public static function vector(string $name, int $dims, VectorMetric $metric = VectorMetric::Cosine): self
    {
        $dims = Assert::atMost(Assert::positive($dims, 'A vector\'s dims'), self::MAX_DIMS, 'A vector\'s dims');

        return new self($name, FieldType::Vector, dims: $dims, metric: $metric);
    }

    public static function object(string $name, self ...$fields): self
    {
        return new self($name, FieldType::Object, fields: self::children($name, $fields));
    }

    public static function nested(string $name, self ...$fields): self
    {
        return new self($name, FieldType::Nested, fields: self::children($name, $fields));
    }

    /**
     * For adapters reporting a live field they cannot express neutrally.
     */
    public static function unknown(string $name): self
    {
        return new self($name, FieldType::Unknown);
    }

    /**
     * @param list<self> $fields
     * @return array<string, self>
     */
    public static function byName(array $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            if (isset($byName[$field->name])) {
                throw new InvalidArgumentException(sprintf('The field %s is declared twice.', Assert::show($field->name)));
            }
            $byName[$field->name] = $field;
        }

        return $byName;
    }

    /**
     * The declarative attributes, defaults omitted, so equal fields have equal arrays and a manifest stays terse.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = ['type' => $this->type->value];
        if ($this->language !== null) {
            $attributes['language'] = $this->language;
        }
        if ($this->boost !== 1.0) {
            $attributes['boost'] = $this->boost;
        }
        foreach (['folding' => $this->folding, 'filterable' => $this->filterable, 'sortable' => $this->sortable, 'facetable' => $this->facetable] as $flag => $on) {
            if ($on) {
                $attributes[$flag] = true;
            }
        }
        if ($this->dims !== null) {
            $attributes['dims'] = $this->dims;
        }
        if ($this->metric !== null) {
            $attributes['metric'] = $this->metric->value;
        }
        if ($this->fields !== []) {
            $attributes['fields'] = self::mapToArray($this->fields);
        }

        return $attributes;
    }

    /**
     * @param list<self> $fields
     * @return array<string, array<string, mixed>>
     */
    public static function mapToArray(array $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            $map[$field->name] = $field->toArray();
        }

        return $map;
    }

    public static function fromArray(string $name, mixed $data, string $path, int $depth = 1): self
    {
        Assert::atMost($depth, self::MAX_DEPTH, $path . '\'s nesting depth');
        $type = Input::string(Input::map($data, $path)['type'] ?? null, $path . '.type');
        // Unknown has no keys: a live-only type is never declarable.
        if (!isset(self::KEYS[$type])) {
            throw new InvalidArgumentException(sprintf('%s.type %s is not a field type.', $path, Assert::show($type)));
        }
        [$required, $optional] = self::KEYS[$type];
        $data = Input::object($data, $path, ['type', ...$required], $optional);
        $flag = static fn(string $key): bool => isset($data[$key]) && Input::bool($data[$key], $path . '.' . $key);

        return match (FieldType::from($type)) {
            FieldType::Text => self::text(
                $name,
                isset($data['language']) ? Input::string($data['language'], $path . '.language') : null,
                isset($data['boost']) ? (float) Input::number($data['boost'], $path . '.boost') : 1.0,
                $flag('folding'),
            ),
            FieldType::Keyword => self::keyword($name, $flag('filterable'), $flag('sortable'), $flag('facetable')),
            FieldType::Integer => self::integer($name, $flag('filterable'), $flag('sortable'), $flag('facetable')),
            FieldType::Float => self::float($name, $flag('filterable'), $flag('sortable'), $flag('facetable')),
            FieldType::Date => self::date($name, $flag('filterable'), $flag('sortable'), $flag('facetable')),
            FieldType::Boolean => self::boolean($name, $flag('filterable'), $flag('facetable')),
            FieldType::Geo => self::geo($name, $flag('filterable'), $flag('sortable')),
            FieldType::Vector => self::vector($name, Input::int($data['dims'], $path . '.dims'), self::metric($data, $path)),
            FieldType::Object => self::object($name, ...self::mapFromArray($data['fields'], $path . '.fields', $depth + 1)),
            FieldType::Nested => self::nested($name, ...self::mapFromArray($data['fields'], $path . '.fields', $depth + 1)),
        };
    }

    /**
     * @return list<self>
     */
    public static function mapFromArray(mixed $data, string $path, int $depth = 1): array
    {
        $fields = [];
        foreach (Input::map($data, $path) as $name => $field) {
            // Checked before it becomes part of a path, so error messages never echo a raw key.
            $name = Assert::fieldName((string) $name, 'In ' . $path . ', the field name');
            $fields[] = self::fromArray($name, $field, $path . '.' . $name, $depth);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function metric(array $data, string $path): VectorMetric
    {
        if (!isset($data['metric'])) {
            return VectorMetric::Cosine;
        }
        $metric = Input::string($data['metric'], $path . '.metric');

        return VectorMetric::tryFrom($metric) ?? throw new InvalidArgumentException(sprintf('%s.metric %s is not a vector metric.', $path, Assert::show($metric)));
    }

    /**
     * @param array<self> $fields
     * @return list<self>
     */
    private static function children(string $name, array $fields): array
    {
        Assert::positive(count($fields), sprintf('The sub-field count of "%s"', $name));
        self::byName(array_values($fields));

        return array_values($fields);
    }
}
