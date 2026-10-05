<?php

namespace Base\Wikidoc\Search;

/**
 * One result: a page of a manual, or a section of it.
 */
final class SearchHit implements \JsonSerializable
{
    public function __construct(
        public readonly string $manual,
        public readonly string $version,
        public readonly string $path,
        public readonly string $title,
        public readonly ?string $section = null,
        public readonly ?string $anchor = null,
        public readonly string $snippet = '',
        public readonly float $score = 0.0,
        public ?string $url = null,
        public ?string $label = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'manual' => $this->manual,
            'label' => $this->label ?? $this->manual,
            'version' => $this->version,
            'path' => $this->path,
            'title' => $this->title,
            'section' => $this->section,
            'anchor' => $this->anchor,
            'snippet' => $this->snippet,
            'url' => $this->url,
        ];
    }
}
