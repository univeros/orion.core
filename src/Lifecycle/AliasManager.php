<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\IndexName;
use Orion\Registry\EngineRegistry;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Psr\Clock\ClockInterface;

use function is_string;
use function sprintf;

/**
 * The atomic alias swap (v1 §8.2). Every swap is recorded in `orion_alias`, so core knows the current physical index
 * of every alias on every engine; on an engine with {@see Capability::NativeAliases} the engine's alias is moved in
 * the same transaction, and the application keeps talking to the alias. Elsewhere the row is the indirection and
 * {@see target()} hands the adapter the physical index.
 */
final readonly class AliasManager
{
    public function __construct(private Storage $storage, private EngineRegistry $engines, private ClockInterface $clock)
    {
    }

    public function swap(string $engine, IndexName $alias, IndexName $physical): void
    {
        $adapter = $this->engines->get($engine);
        $this->storage->transaction(function () use ($engine, $adapter, $alias, $physical): void {
            $row = ['alias' => $alias->value, 'engine' => $engine, 'physical' => $physical->value, 'swapped_at' => $this->clock->now()];
            $existing = $this->storage->findOne(Schema::ALIAS, ['alias' => $alias->value]);
            if ($existing === null) {
                $this->storage->insert(Schema::ALIAS, $row);
            } elseif ($existing['engine'] !== $engine) {
                throw new InvalidArgumentException(sprintf('The alias %s belongs to the engine %s, not %s.', Assert::show($alias->value), Assert::show(self::name($existing['engine'])), Assert::show($engine)));
            } else {
                $this->storage->update(Schema::ALIAS, ['alias' => $alias->value], $row);
            }
            if ($adapter->supports(Capability::NativeAliases)) {
                $adapter->alias($alias, $physical);
            }
        });
    }

    /**
     * The physical index the alias points at, or null when nothing was ever swapped onto it.
     */
    public function current(IndexName $alias): ?IndexName
    {
        $row = $this->storage->findOne(Schema::ALIAS, ['alias' => $alias->value]);

        return $row === null ? null : IndexName::of(self::name($row['physical']));
    }

    /**
     * What to hand the engine adapter for this alias: the alias itself where the engine resolves it, the physical
     * index where core keeps the indirection.
     */
    public function target(IndexName $alias): IndexName
    {
        $row = $this->storage->findOne(Schema::ALIAS, ['alias' => $alias->value])
            ?? throw new InvalidArgumentException(sprintf('The alias %s is not registered.', Assert::show($alias->value)));

        return $this->engines->get(self::name($row['engine']))->supports(Capability::NativeAliases) ? $alias : IndexName::of(self::name($row['physical']));
    }

    private static function name(mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException('The alias table holds names as strings.');
    }
}
