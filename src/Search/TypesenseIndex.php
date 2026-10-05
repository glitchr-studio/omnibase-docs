<?php

namespace Base\Wikidoc\Search;

use Base\Wikidoc\Manual\Manual;
use Base\Wikidoc\Manual\ManualVersion;

/**
 * The manuals in Typesense: one collection per manual and version -
 * `<prefix>_<manual>_<version>`, fields title, section, text - filled by
 * `wikidoc:index` from the same records the local search reads.
 */
class TypesenseIndex
{
    /** Typesense takes at most 50 searches in one multi_search call. */
    protected const BATCH = 40;

    public function __construct(
        protected readonly TypesenseClientInterface $client,
        protected readonly ManualIndex $index,
        protected readonly string $prefix = 'wikidoc',
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->client->isAvailable();
    }

    /** "wikidoc_glitchr-omnitrade_1-x" */
    public function collection(Manual $manual, ManualVersion $version): string
    {
        $slug = static fn (string $s): string => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '', '-');

        return $this->prefix.'_'.$slug($manual->getKey()).'_'.$slug($version->name);
    }

    /**
     * Drops the version's collection and writes it again.
     *
     * @return int the number of records indexed
     */
    public function index(Manual $manual, ManualVersion $version): int
    {
        $collection = $this->collection($manual, $version);
        $this->index->forget($manual, $version);
        $records = $this->index->records($manual, $version);

        if (\in_array($collection, $this->client->collections(), true)) {
            $this->client->dropCollection($collection);
        }
        $this->client->createCollection([
            'name' => $collection,
            'fields' => [
                ['name' => 'manual', 'type' => 'string', 'facet' => true],
                ['name' => 'version', 'type' => 'string', 'facet' => true],
                ['name' => 'path', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'section', 'type' => 'string', 'optional' => true],
                ['name' => 'anchor', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'text', 'type' => 'string'],
                // 0: a page, 1: a section of it - the page first, for the same match.
                ['name' => 'rank', 'type' => 'int32'],
            ],
            'default_sorting_field' => 'rank',
        ]);

        $documents = [];
        foreach ($records as $i => $record) {
            $documents[] = array_filter([
                'id' => (string) $i,
                'manual' => $manual->getKey(),
                'version' => $version->name,
                'path' => (string) $record['path'],
                'title' => (string) $record['title'],
                'section' => $record['section'] ?? null,
                'anchor' => $record['anchor'] ?? null,
                'text' => (string) ($record['text'] ?? ''),
                'rank' => null === ($record['section'] ?? null) ? 0 : 1,
            ], static fn ($value): bool => null !== $value);
        }

        return $this->client->import($collection, $documents);
    }

    /** Removes the collections of manuals and versions that are no longer published. */
    public function prune(array $keep): int
    {
        $dropped = 0;
        foreach ($this->client->collections() as $collection) {
            if (str_starts_with($collection, $this->prefix.'_') && !\in_array($collection, $keep, true)) {
                $this->client->dropCollection($collection);
                ++$dropped;
            }
        }

        return $dropped;
    }

    /**
     * @param list<array{0: Manual, 1: ManualVersion}> $targets
     *
     * @return SearchResult|null null when a collection is missing or a search failed: the caller answers locally
     */
    public function search(string $query, array $targets, int $limit = 20): ?SearchResult
    {
        $hits = [];
        $order = [];
        $found = 0;

        foreach (array_chunk($targets, self::BATCH) as $batch) {
            $searches = [];
            foreach ($batch as [$manual, $version]) {
                $searches[] = [
                    'collection' => $this->collection($manual, $version),
                    'q' => $query,
                    'query_by' => 'title,section,text',
                    'query_by_weights' => '4,3,1',
                    'prefix' => 'true',
                    'num_typos' => 2,
                    'sort_by' => '_text_match:desc,rank:asc',
                    'per_page' => $limit,
                    'highlight_fields' => 'text',
                    'highlight_affix_num_tokens' => 14,
                    'exclude_fields' => 'text',
                ];
            }

            foreach ($this->client->multiSearch($searches) as $i => $result) {
                if (isset($result['error']) || !isset($result['hits'])) {
                    return null;
                }
                [$manual, $version] = $batch[$i];
                $found += (int) ($result['found'] ?? \count($result['hits']));
                foreach ($result['hits'] as $hit) {
                    $document = $hit['document'] ?? [];
                    // text_match is a 64-bit integer whose low bits carry the field's
                    // weight: as a float it loses them, and a title no longer outranks the text.
                    $order[] = [(int) ($hit['text_match'] ?? 0), -(int) ($document['rank'] ?? 0)];
                    $hits[] = new SearchHit(
                        manual: $manual->getKey(),
                        version: $version->name,
                        path: (string) ($document['path'] ?? ''),
                        title: (string) ($document['title'] ?? ''),
                        section: $document['section'] ?? null,
                        anchor: $document['anchor'] ?? null,
                        snippet: self::snippet($hit),
                        score: (float) ($hit['text_match'] ?? 0),
                        label: $manual->label,
                    );
                }
            }
        }

        // The best match first, a page before its sections, then by manual: across collections.
        $keys = array_keys($hits);
        usort($keys, static fn (int $a, int $b): int => [$order[$b], $hits[$a]->manual, $hits[$a]->path] <=> [$order[$a], $hits[$b]->manual, $hits[$b]->path]);
        $hits = array_map(static fn (int $key): SearchHit => $hits[$key], \array_slice($keys, 0, $limit));

        return new SearchResult($query, $hits, $found, SearchResult::TYPESENSE);
    }

    /** The text's highlighted passage, as plain text: the page marks the words itself. */
    protected static function snippet(array $hit): string
    {
        foreach ((array) ($hit['highlights'] ?? []) as $highlight) {
            if ('text' === ($highlight['field'] ?? null) && isset($highlight['snippet'])) {
                return trim(html_entity_decode(strip_tags((string) $highlight['snippet']), \ENT_QUOTES | \ENT_HTML5));
            }
        }
        $snippet = $hit['highlight']['text']['snippet'] ?? null;

        return \is_string($snippet) ? trim(html_entity_decode(strip_tags($snippet), \ENT_QUOTES | \ENT_HTML5)) : '';
    }
}
