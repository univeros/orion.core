<?php

declare(strict_types=1);

namespace Orion\Query;

use Orion\Support\Assert;
use Orion\Support\Input;

use function intdiv;

use const PHP_INT_MAX;

/**
 * Offset pagination: 1-based page number and page size. The offset always fits an int; how deep an engine lets you
 * page is the adapter's to refuse.
 */
final readonly class Page
{
    public const int MAX_SIZE = 1000;

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
