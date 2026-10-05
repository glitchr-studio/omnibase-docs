<?php

namespace Base\Wikidoc\Tests\Search;

use Base\Wikidoc\Search\TypesenseClientInterface;

/**
 * Typesense as the tests see it: collections kept in memory, a search that
 * matches whole words in title, section and text and answers in the shape
 * the real server answers (hits, document, highlights, text_match, found) -
 * or a server that is down.
 */
final class FakeTypesense implements TypesenseClientInterface
{
    public bool $up = true;

    /** @var array<string, array<string, mixed>> the schemas, by collection */
    public array $schemas = [];

    /** @var array<string, list<array<string, mixed>>> the documents, by collection */
    public array $documents = [];

    /** @var list<list<array<string, mixed>>> every multi_search made */
    public array $searches = [];

    public function isAvailable(): bool
    {
        return $this->up;
    }

    public function collections(): array
    {
        $this->guard();

        return array_keys($this->schemas);
    }

    public function createCollection(array $schema): void
    {
        $this->guard();
        $this->schemas[$schema['name']] = $schema;
        $this->documents[$schema['name']] = [];
    }

    public function dropCollection(string $name): void
    {
        $this->guard();
        unset($this->schemas[$name], $this->documents[$name]);
    }

    public function import(string $collection, array $documents): int
    {
        $this->guard();
        $this->documents[$collection] = array_merge($this->documents[$collection] ?? [], $documents);

        return \count($documents);
    }

    public function multiSearch(array $searches): array
    {
        $this->guard();
        $this->searches[] = $searches;

        $results = [];
        foreach ($searches as $search) {
            if (!isset($this->schemas[$search['collection']])) {
                $results[] = ['code' => 404, 'error' => 'Not found.'];
                continue;
            }
            $hits = [];
            foreach ($this->documents[$search['collection']] as $document) {
                $haystack = mb_strtolower(($document['title'] ?? '').' '.($document['section'] ?? '').' '.($document['text'] ?? ''));
                if (!str_contains($haystack, mb_strtolower((string) $search['q']))) {
                    continue;
                }
                $text = (string) ($document['text'] ?? '');
                $at = max(0, (int) mb_stripos($text, (string) $search['q']) - 20);
                $hits[] = [
                    'document' => array_diff_key($document, ['text' => true]),
                    'highlights' => [['field' => 'text', 'snippet' => preg_replace('/('.preg_quote((string) $search['q'], '/').')/i', '<mark>$1</mark>', mb_substr($text, $at, 80))]],
                    'text_match' => str_contains(mb_strtolower((string) ($document['title'] ?? '')), mb_strtolower((string) $search['q'])) ? 200 : 100,
                ];
            }
            $results[] = ['found' => \count($hits), 'hits' => \array_slice($hits, 0, (int) ($search['per_page'] ?? 10))];
        }

        return $results;
    }

    private function guard(): void
    {
        if (!$this->up) {
            throw new \RuntimeException('Typesense is down.');
        }
    }
}
