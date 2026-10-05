<?php

namespace Base\Wikidoc\Search;

/**
 * The few calls the manuals make to Typesense. BundleTypesenseClient makes
 * them through glitchr/typesense-bundle's connection; a test hands in its
 * own. Every method may throw: the caller falls back on the local index.
 */
interface TypesenseClientInterface
{
    /** Whether the server answers at all. Never throws. */
    public function isAvailable(): bool;

    /** @return list<string> the names of the collections there are */
    public function collections(): array;

    /** @param array<string, mixed> $schema */
    public function createCollection(array $schema): void;

    public function dropCollection(string $name): void;

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return int how many were taken
     */
    public function import(string $collection, array $documents): int;

    /**
     * One search per entry, in one call (multi_search).
     *
     * @param list<array<string, mixed>> $searches each with its `collection`
     *
     * @return list<array<string, mixed>> one result per search, in order; a failed one carries `error`
     */
    public function multiSearch(array $searches): array;
}
