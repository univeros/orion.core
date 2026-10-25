<?php

declare(strict_types=1);

namespace Orion;

use InvalidArgumentException;
use Orion\Support\Assert;

use function preg_match;
use function sprintf;
use function strlen;

/**
 * The name of an index, alias or physical, valid on every engine: lowercase ASCII letters, digits, `_` and `-`,
 * starting with a letter or digit, at most 255 bytes (the strictest rules of Elasticsearch, OpenSearch, Solr and
 * Meilisearch, intersected).
 */
final readonly class IndexName
{
    private const string PATTERN = '/^[a-z0-9][a-z0-9_-]*\z/';
    private const int MAX_LENGTH = 255;

    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s is not a valid index name: use lowercase letters, digits, "_" and "-", starting with a letter or digit, at most %d bytes.',
                Assert::show($value),
                self::MAX_LENGTH,
            ));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
