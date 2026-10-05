<?php

namespace Base\Wikidoc\Search;

use Base\Wikidoc\Manual\Manual;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Manual\ManualVersion;
use Psr\Log\LoggerInterface;

/**
 * The manuals' search: Typesense when it is switched on and answers
 * (`wikidoc.search.typesense`), the pages' own index otherwise. The result
 * says which of the two answered.
 *
 * A search covers one manual (in one version, or its default one) or every
 * manual, each in its default version.
 */
class ManualSearch
{
    public function __construct(
        protected readonly ManualRegistry $manuals,
        protected readonly LocalSearch $local,
        protected readonly ?TypesenseIndex $typesense = null,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function search(string $query, ?string $manual = null, ?string $version = null, int $limit = 20): SearchResult
    {
        $query = trim($query);
        $targets = $this->targets($manual, $version);
        if ('' === $query || [] === $targets) {
            return new SearchResult($query, [], 0, null !== $this->typesense ? SearchResult::TYPESENSE : SearchResult::LOCAL);
        }

        if (null !== $this->typesense) {
            try {
                if (null !== $result = $this->typesense->search($query, $targets, $limit)) {
                    return $result;
                }
                $this->logger?->notice('wikidoc: a manual is not in the Typesense index (run wikidoc:index); the local index answered.');
            } catch (\Throwable $e) {
                $this->logger?->notice('wikidoc: Typesense did not answer ({message}); the local index answered.', ['message' => $e->getMessage()]);
            }
        }

        return $this->local->search($query, $targets, $limit);
    }

    /** Which engine would answer now - for a status line, not for a decision. */
    public function engine(): string
    {
        return null !== $this->typesense && $this->typesense->isAvailable() ? SearchResult::TYPESENSE : SearchResult::LOCAL;
    }

    /** @return list<array{0: Manual, 1: ManualVersion}> */
    public function targets(?string $manual = null, ?string $version = null): array
    {
        if (null !== $manual && '' !== $manual) {
            $found = $this->manuals->get($manual);
            $at = $found?->getVersion($version);

            return null !== $found && null !== $at ? [[$found, $at]] : [];
        }

        $targets = [];
        foreach ($this->manuals->all() as $each) {
            if (null !== $at = $each->getDefaultVersion()) {
                $targets[] = [$each, $at];
            }
        }

        return $targets;
    }
}
