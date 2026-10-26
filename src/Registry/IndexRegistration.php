<?php

declare(strict_types=1);

namespace Orion\Registry;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Orion\IndexName;
use Orion\Lifecycle\IndexNamer;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;
use Orion\Support\Input;
use Orion\Support\Row;

use function json_encode;
use function sprintf;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * One versioned physical index as the registry records it: which alias and engine it belongs to, and the schema it
 * was built from, which is what a live index cannot report (the org key, native settings). Persisted as a row of
 * `orion_index`; {@see fromRow()} is the inverse of {@see toRow()} and refuses unknown columns.
 */
final readonly class IndexRegistration
{
    private const array COLUMNS = ['name', 'alias', 'engine', 'version', 'definition', 'schema_hash', 'created_at'];

    public function __construct(
        public IndexName $physical,
        public IndexName $alias,
        public string $engine,
        public int $version,
        public IndexSchema $schema,
        public DateTimeImmutable $createdAt,
    ) {
        Assert::notEmpty($engine, 'The engine name');
        if (!IndexNamer::physical($alias, $version)->equals($physical)) {
            throw new InvalidArgumentException(sprintf('%s is not version %d of %s.', Assert::show($physical->value), $version, Assert::show($alias->value)));
        }
        if (!$schema->index->equals($alias)) {
            throw new InvalidArgumentException(sprintf('The schema of %s cannot be registered for %s.', Assert::show($schema->index->value), Assert::show($alias->value)));
        }
    }

    /**
     * @return array{name: string, alias: string, engine: string, version: int, definition: string, schema_hash: string, created_at: DateTimeImmutable}
     */
    public function toRow(): array
    {
        return [
            'name' => $this->physical->value,
            'alias' => $this->alias->value,
            'engine' => $this->engine,
            'version' => $this->version,
            // As declared, so the round trip keeps the field order; the hash is order independent.
            'definition' => json_encode($this->schema->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'schema_hash' => $this->schema->hash(),
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $row raw driver values: numbers and dates may come back as strings
     * @throws LogicException when the stored hash does not match the stored definition: the registry was altered
     */
    public static function fromRow(array $row): self
    {
        $path = 'orion_index';
        $row = Input::object($row, $path, self::COLUMNS);
        $schema = IndexSchema::fromArray(Row::json($row['definition'], $path . '.definition'));
        if ($schema->hash() !== Input::string($row['schema_hash'], $path . '.schema_hash')) {
            throw new LogicException(sprintf('The registered definition of %s does not match its hash; the registry was altered.', Assert::show($schema->index->value)));
        }

        return new self(
            IndexName::of(Input::string($row['name'], $path . '.name')),
            IndexName::of(Input::string($row['alias'], $path . '.alias')),
            Input::string($row['engine'], $path . '.engine'),
            Row::int($row['version'], $path . '.version'),
            $schema,
            Row::date($row['created_at'], $path . '.created_at'),
        );
    }
}
