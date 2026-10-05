<?php

namespace Base\Wikidoc\Search;

use Base\Wikidoc\Documentation\MarkdownRenderer;
use Base\Wikidoc\Documentation\SearchIndexBuilder;
use Base\Wikidoc\Manual\Manual;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Manual\ManualVersion;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The searchable records of a manual's version: one per page and one per
 * heading - title, section, text. They are SearchIndexBuilder's, the index
 * the back office's palette has always used; here it is built per manual
 * and version, sent to Typesense by `wikidoc:index`, and searched in PHP
 * when the engine does not answer.
 */
class ManualIndex
{
    /** @var array<string, list<array<string, mixed>>> */
    protected array $memo = [];

    public function __construct(
        protected readonly ManualRegistry $manuals,
        protected readonly MarkdownRenderer $renderer,
        protected readonly ?CacheItemPoolInterface $cache = null,
        /** Seconds a version's records are kept; 0: rebuilt whenever a file changed. */
        protected readonly int $ttl = 0,
    ) {
    }

    /** @return list<array{path: string, title: string, section: ?string, anchor: ?string, text: string, root: string}> */
    public function records(Manual $manual, ManualVersion $version): array
    {
        $id = $manual->getKey().'@'.$version->name;
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $pages = $this->manuals->pages($manual, $version);
        // No pool handed to the builder: its key is the files' fingerprint
        // alone, and two manuals may well hold the same file names.
        $build = fn (): array => (new SearchIndexBuilder($pages, $this->renderer))->build();
        if (null === $this->cache) {
            return $this->memo[$id] = $build();
        }

        $key = 'wikidoc.manual.index.'.md5($id.'|'.$version->root.($this->ttl > 0 ? '' : '|'.$pages->getFingerprint()));
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            $item->set($build());
            if ($this->ttl > 0) {
                $item->expiresAfter($this->ttl);
            }
            $this->cache->save($item);
        }

        return $this->memo[$id] = $item->get();
    }

    /** Forget a version's records (after `wikidoc:sync` or `wikidoc:index`). */
    public function forget(Manual $manual, ManualVersion $version): void
    {
        $id = $manual->getKey().'@'.$version->name;
        unset($this->memo[$id]);
        $this->cache?->deleteItem('wikidoc.manual.index.'.md5($id.'|'.$version->root));
    }
}
