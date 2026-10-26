<?php

declare(strict_types=1);

namespace Orion\Lifecycle;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\IndexName;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Registry\IndexRegistry;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Psr\Clock\ClockInterface;

use function array_map;
use function is_string;
use function sprintf;

/**
 * The atomic alias swap (v1 §8.2). Every swap is recorded in `orion_alias`, so core knows the current physical index
 * of every alias on every engine; on an engine with {@see Capability::NativeAliases} the engine's alias is moved in
 * the same transaction, and the application keeps talking to the alias. Elsewhere the row is the indirection and
 * {@see target()} hands the adapter the physical index.
 *
 * An alias only ever points at a physical index registered for it on its engine; a row that says otherwise is
 * refused on read as well as on write, so a corrupted registry fails closed rather than redirecting an index.
 */
final readonly class AliasManager
{
    public function __construct(private Storage $storage, private EngineRegistry $engines, private IndexRegistry $registry, private ClockInterface $clock)
    {
    }

    /**
     * @throws InvalidArgumentException when the physical index is not registered for this alias on this engine, or
     *                                  the alias belongs to another engine
     */
    public function swap(string $engine, IndexName $alias, IndexName $physical): void
    {
        $adapter = $this->engines->get($engine);
        $registration = $this->registry->find($physical);
        if ($registration === null || !$registration->alias->equals($alias) || $registration->engine !== $engine) {
            throw new InvalidArgumentException(sprintf('%s is not registered as a version of %s on the engine %s.', Assert::show($physical->value), Assert::show($alias->value), Assert::show($engine)));
        }
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
     * The registration the alias points at, or null when nothing was ever swapped onto it.
     *
     * @throws LogicException when the alias points at an index that is not registered for it on its engine
     */
    public function current(IndexName $alias): ?IndexRegistration
    {
        $row = $this->storage->findOne(Schema::ALIAS, ['alias' => $alias->value]);
        if ($row === null) {
            return null;
        }
        $physical = IndexName::of(self::name($row['physical']));
        $registration = $this->registry->find($physical);
        if ($registration === null || !$registration->alias->equals($alias) || $registration->engine !== self::name($row['engine'])) {
            throw new LogicException(sprintf('The alias %s points at %s, which is not registered as one of its versions on its engine.', Assert::show($alias->value), Assert::show($physical->value)));
        }

        return $registration;
    }

    /**
     * What to hand the engine adapter for this alias: the alias itself where the engine resolves it, the physical
     * index where core keeps the indirection.
     */
    public function target(IndexName $alias): IndexName
    {
        return $this->targetOf($this->current($alias)
            ?? throw new InvalidArgumentException(sprintf('The alias %s is not registered.', Assert::show($alias->value))));
    }

    /**
     * {@see target()} for a registration already read.
     */
    public function targetOf(IndexRegistration $current): IndexName
    {
        return $this->engines->get($current->engine)->supports(Capability::NativeAliases) ? $current->alias : $current->physical;
    }

    /**
     * Every alias Orion has swapped onto an index, by name.
     *
     * @return list<IndexName>
     */
    public function aliases(): array
    {
        return array_map(static fn(array $row): IndexName => IndexName::of(self::name($row['alias'])), $this->storage->findMany(Schema::ALIAS, [], ['alias' => 'asc']));
    }

    private static function name(mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException('The alias table holds names as strings.');
    }
}
