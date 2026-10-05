<?php

namespace Base\Wikidoc\Search;

/**
 * What a search returned, and who answered: "typesense" (the engine) or
 * "local" (the pages' own index, when the engine is off or does not answer).
 */
final class SearchResult implements \JsonSerializable
{
    public const TYPESENSE = 'typesense';
    public const LOCAL = 'local';

    /** @param list<SearchHit> $hits */
    public function __construct(
        public readonly string $query,
        public readonly array $hits,
        public readonly int $found,
        public readonly string $engine,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['query' => $this->query, 'engine' => $this->engine, 'found' => $this->found, 'hits' => $this->hits];
    }
}
