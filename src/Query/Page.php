<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\Support\Assert;
use Orion\Support\Input;

use function intdiv;

use const PHP_INT_MAX;

/**
 * Offset pagination: 1-based page number and page size. The offset always fits an int; the searcher refuses a page
 * past {@see WINDOW} with a typed `UnsupportedClause`, the same on every engine.
 */
final readonly class Page
{
    public const int MAX_SIZE = 1000;
    /** The deepest result offset pagination reaches: Elasticsearch's `max_result_window`, the lowest of the engines. */
    public const int WINDOW = 10000;

    public function __construct(public int $page, public int $size)
    {
        Assert::atMost(Assert::positive($size, 'A page size'), self::MAX_SIZE, 'A page size');
        Assert::atMost(Assert::positive($page, 'A page number'), intdiv(PHP_INT_MAX, $size), 'A page number');
    }

    /**
     * @return array{page: int, size: int}
     */
    public function toArray(): array
    {
        return ['page' => $this->page, 'size' => $this->size];
    }

    /**
     * The result offset of the page's last hit.
     */
    public function depth(): int
    {
        return $this->page * $this->size;
    }

    public static function fromArray(mixed $data, string $path): self
    {
        $data = Input::object($data, $path, ['page', 'size']);
        $page = Input::int($data['page'], $path . '.page');
        $size = Input::int($data['size'], $path . '.size');
        Assert::atMost(Assert::positive($size, $path . '.size'), self::MAX_SIZE, $path . '.size');
        Assert::atMost(Assert::positive($page, $path . '.page'), intdiv(PHP_INT_MAX, $size), $path . '.page');

        return new self($page, $size);
    }
}
