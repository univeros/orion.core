<?php

declare(strict_types=1);

namespace Orion\Adapter\InMemory;

use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\Direction;
use Orion\Query\Facet;
use Orion\Query\Sort;

use function array_slice;
use function htmlspecialchars;
use function in_array;
use function is_bool;
use function preg_match;
use function preg_replace_callback;
use function str_starts_with;
use function strcmp;
use function strtolower;
use function uksort;
use function usort;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Orders matched documents, counts facets and marks highlights, the way a search engine shapes a response.
 */
final readonly class Shaper
{
    public function __construct(private Matcher $matcher)
    {
    }

    /**
     * Sorts by the given fields, missing values last in either direction; ties, and no sort at all, fall back to
     * score descending, then id, so the development engine answers the same way every time.
     *
     * @param list<array{id: string, score: float, source: array<string, mixed>}> $matches
     * @param list<Sort> $sorts
     * @return list<array{id: string, score: float, source: array<string, mixed>}>
     */
    public function order(array $matches, array $sorts): array
    {
        usort($matches, function (array $a, array $b) use ($sorts): int {
            foreach ($sorts as $sort) {
                $order = $sort->field === Sort::SCORE
                    ? ($sort->direction === Direction::Desc ? $b['score'] <=> $a['score'] : $a['score'] <=> $b['score'])
                    : $this->compareField($a['source'], $b['source'], $sort->field, $sort->direction);
                if ($order !== 0) {
                    return $order;
                }
            }

            return ($b['score'] <=> $a['score']) ?: strcmp($a['id'], $b['id']);
        });

        return $matches;
    }

    /**
     * @param list<array{id: string, score: float, source: array<string, mixed>}> $matches
     * @param list<Facet> $facets
     * @return array<string, array<string, int>>
     */
    public function facets(array $matches, array $facets): array
    {
        $counts = [];
        foreach ($facets as $facet) {
            $buckets = [];
            foreach ($matches as $match) {
                foreach ($this->matcher->values($match['source'], $facet->field) as $value) {
                    $key = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                    $buckets[$key] = ($buckets[$key] ?? 0) + 1;
                }
            }
            uksort($buckets, static fn(int|string $a, int|string $b): int => ($buckets[$b] <=> $buckets[$a]) ?: strcmp((string) $a, (string) $b));
            $counts[$facet->field] = array_slice($buckets, 0, $facet->size, true);
        }

        return $counts;
    }

    /**
     * The whole field value, HTML-escaped, with each matched token wrapped in `<em>`, for every requested text field
     * that matched.
     *
     * @param array<string, mixed> $source
     * @param list<string> $fields
     * @param list<Clause> $clauses
     * @return array<string, list<string>>
     */
    public function highlights(array $source, array $fields, array $clauses): array
    {
        $highlights = [];
        foreach ($fields as $field) {
            $terms = [];
            $prefixes = [];
            foreach ($clauses as $clause) {
                if ($clause->field !== $field) {
                    continue;
                }
                if ($clause->kind === ClauseKind::Prefix) {
                    $prefixes[] = (string) $clause->value;
                } else {
                    $terms = [...$terms, ...Matcher::tokens((string) $clause->value)];
                }
            }
            $fragments = [];
            foreach ($this->matcher->values($source, $field) as $value) {
                $marked = 0;
                // Every piece is escaped, so the fragment is HTML whose only markup is <em>.
                $fragment = preg_replace_callback('/[\p{L}\p{N}]+|[^\p{L}\p{N}]+/u', static function (array $piece) use ($terms, $prefixes, &$marked): string {
                    $escaped = htmlspecialchars($piece[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $token = strtolower($piece[0]);
                    $matches = in_array($token, $terms, true);
                    foreach ($prefixes as $prefix) {
                        $matches = $matches || ($prefix !== '' && str_starts_with($token, $prefix));
                    }
                    if (!$matches || preg_match('/^[\p{L}\p{N}]+$/u', $piece[0]) !== 1) {
                        return $escaped;
                    }
                    ++$marked;

                    return '<em>' . $escaped . '</em>';
                }, (string) $value);
                if ($marked > 0 && $fragment !== null) {
                    $fragments[] = $fragment;
                }
            }
            if ($fragments !== []) {
                $highlights[$field] = $fragments;
            }
        }

        return $highlights;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareField(array $a, array $b, string $field, Direction $direction): int
    {
        $left = $this->matcher->values($a, $field)[0] ?? null;
        $right = $this->matcher->values($b, $field)[0] ?? null;
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }
        $order = Matcher::compare($left, $right);

        return $direction === Direction::Desc ? -$order : $order;
    }
}
