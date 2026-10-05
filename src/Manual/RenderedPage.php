<?php

namespace Base\Wikidoc\Manual;

/**
 * A manual's page, ready for the template: its HTML, the headings its
 * "on this page" list is made of, the address its source is edited at.
 */
final class RenderedPage
{
    /** @param list<array{level: int, text: string, id: string}> $headings */
    public function __construct(
        public readonly string $html,
        public readonly array $headings,
        /** Whether the Markdown has a first-level title of its own (else the template prints the page's). */
        public readonly bool $titled,
        public readonly ?string $editUrl,
        public readonly ?string $sourceUrl,
        /** The file's path inside the repository: "docs/webhooks.md". */
        public readonly ?string $source,
    ) {
    }
}
