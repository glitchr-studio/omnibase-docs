<?php

namespace Base\Wikidoc\Search;

use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Client;

/**
 * Typesense through glitchr/typesense-bundle: its manager's connection (the
 * default one, or the one `wikidoc.search.typesense.connection` names) gives
 * the client; the manuals' collections are created here, not from an entity
 * mapping - a page is a file, not a row.
 */
class BundleTypesenseClient implements TypesenseClientInterface
{
    public function __construct(
        protected readonly TypesenseManager $manager,
        protected readonly ?string $connection = null,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            return (bool) ($this->client()->getHealth()->retrieve()['ok'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function collections(): array
    {
        return array_values(array_map(static fn (array $c): string => (string) $c['name'], $this->client()->getCollections()->retrieve()));
    }

    public function createCollection(array $schema): void
    {
        $this->client()->getCollections()->create($schema);
    }

    public function dropCollection(string $name): void
    {
        $this->client()->getCollections()[$name]->delete();
    }

    public function import(string $collection, array $documents): int
    {
        if ([] === $documents) {
            return 0;
        }
        $results = $this->client()->getCollections()[$collection]->getDocuments()->import($documents, ['action' => 'upsert']);

        return \count(array_filter((array) $results, static fn ($r): bool => \is_array($r) && !empty($r['success'])));
    }

    public function multiSearch(array $searches): array
    {
        $response = $this->client()->getMultiSearch()->perform(['searches' => $searches]);

        return array_values((array) ($response['results'] ?? []));
    }

    protected function client(): Client
    {
        $connection = $this->manager->getConnection($this->connection);
        $client = $connection?->getClient();
        if (null === $client) {
            throw new \RuntimeException(sprintf('No Typesense connection "%s".', $this->connection ?? 'default'));
        }

        return $client;
    }
}
