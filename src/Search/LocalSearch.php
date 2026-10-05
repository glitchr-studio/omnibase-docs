<?php

namespace Base\Wikidoc\Search;

use Base\Wikidoc\Manual\Manual;
use Base\Wikidoc\Manual\ManualVersion;

/**
 * The search without an engine: the manuals' records matched in PHP, the
 * way the back office's palette matches them in the browser. Every word of
 * the query must be found; a title outranks a heading, a heading the text,
 * and the start of a word the middle of one. No typo tolerance: that is
 * what Typesense adds.
 */
class LocalSearch
{
    public function __construct(protected readonly ManualIndex $index)
    {
    }

    /**
     * @param list<array{0: Manual, 1: ManualVersion}> $targets
     */
    public function search(string $query, array $targets, int $limit = 20): SearchResult
    {
        $terms = self::terms($query);
        if ([] === $terms) {
            return new SearchResult($query, [], 0, SearchResult::LOCAL);
        }

        $hits = [];
        foreach ($targets as [$manual, $version]) {
            foreach ($this->index->records($manual, $version) as $record) {
                $score = self::score($record, $terms);
                if ($score <= 0) {
                    continue;
                }
                $hits[] = new SearchHit(
                    manual: $manual->getKey(),
                    version: $version->name,
                    path: (string) $record['path'],
                    title: (string) $record['title'],
                    section: $record['section'] ?? null,
                    anchor: $record['anchor'] ?? null,
                    snippet: self::snippet((string) ($record['text'] ?? ''), $terms),
                    score: $score,
                    label: $manual->label,
                );
            }
        }

        usort($hits, static fn (SearchHit $a, SearchHit $b): int => [$b->score, $a->manual, $a->path] <=> [$a->score, $b->manual, $b->path]);

        return new SearchResult($query, \array_slice($hits, 0, $limit), \count($hits), SearchResult::LOCAL);
    }

    /** @return list<string> the query's words, lower case, accents kept */
    public static function terms(string $query): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn (string $t): bool => '' !== $t)));
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $terms
     */
    protected static function score(array $record, array $terms): float
    {
        $title = mb_strtolower((string) $record['title']);
        $section = mb_strtolower((string) ($record['section'] ?? ''));
        $text = mb_strtolower((string) ($record['text'] ?? ''));
        $total = 0.0;

        foreach ($terms as $term) {
            $score = 0;
            $at = mb_strpos($title, $term);
            if (0 === $at) {
                $score = 100;
            } elseif (false !== $at) {
                $score = 60;
            }
            $at = '' !== $section ? mb_strpos($section, $term) : false;
            if (0 === $at) {
                $score = max($score, 80);
            } elseif (false !== $at) {
                $score = max($score, 45);
            }
            if (0 === $score && str_contains($text, $term)) {
                $score = 12;
            }
            if (0 === $score) {
                return 0.0; // every word must be there
            }
            $total += $score;
        }

        // The page itself before a section of it, for the same words.
        return $total + (null === ($record['section'] ?? null) ? 3 : 0);
    }

    /** @param list<string> $terms */
    public static function snippet(string $text, array $terms, int $length = 180): string
    {
        if ('' === $text) {
            return '';
        }
        $lower = mb_strtolower($text);
        $at = false;
        foreach ($terms as $term) {
            if (false !== $at = mb_strpos($lower, $term)) {
                break;
            }
        }
        $start = max(0, (int) $at - 60);

        return ($start > 0 ? '…' : '').trim(mb_substr($text, $start, $length)).(mb_strlen($text) > $start + $length ? '…' : '');
    }
}
