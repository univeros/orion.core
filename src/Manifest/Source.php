<?php

declare(strict_types=1);

namespace Orion\Manifest;

use InvalidArgumentException;
use Orion\Support\Assert;
use Orion\Support\Input;

use function preg_match;
use function sprintf;

/**
 * Where an index's documents come from, as the manifest's `source` declares it (v1 §12, §13): the application's
 * entity class and the {@see \Orion\Projection\Projector} that turns one into a document. Core records the names
 * for hosts and agents; it never instantiates them.
 */
final readonly class Source
{
    private const string CLASS_NAME = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    /**
     * @param class-string|string|null $entity
     * @param class-string|string|null $projector
     */
    public function __construct(public ?string $entity = null, public ?string $projector = null)
    {
        foreach (['entity' => $entity, 'projector' => $projector] as $key => $class) {
            if ($class !== null && preg_match(self::CLASS_NAME, $class) !== 1) {
                throw new InvalidArgumentException(sprintf('The source %s %s is not a class name.', $key, Assert::show($class)));
            }
        }
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, [], ['entity', 'projector']);

        return new self(
            isset($data['entity']) ? Input::string($data['entity'], $path . '.entity') : null,
            isset($data['projector']) ? Input::string($data['projector'], $path . '.projector') : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [...($this->entity === null ? [] : ['entity' => $this->entity]), ...($this->projector === null ? [] : ['projector' => $this->projector])];
    }
}
