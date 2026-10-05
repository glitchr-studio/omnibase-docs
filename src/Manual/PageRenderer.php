<?php

namespace Base\Wikidoc\Manual;

use Base\Wikidoc\Documentation\DocPage;
use Base\Wikidoc\Documentation\DocumentationRegistry;

/**
 * Renders one page of a manual for the public site: the Markdown to HTML,
 * its relative links rewritten, its headings listed, its "edit this page"
 * address worked out.
 *
 * A link in a repository's Markdown is written for the repository -
 * `[webhooks](webhooks.md)`, `[the bridge](../src/Bridge/Symfony)`,
 * `![](img/flow.png)`. Here:
 *  - a link to another Markdown file of the manual goes to that page;
 *  - a picture of the manual is served by the site (the `asset` address);
 *  - anything else in the repository goes to the file on the forge
 *    (`{repository}/blob/{branch}/{file}`), when the repository is known;
 *  - what is absolute, or an anchor, is left alone.
 */
class PageRenderer
{
    public const PICTURES = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif'];

    public function __construct(
        protected readonly ManualRegistry $manuals,
        protected readonly ManualMarkdownRenderer $markdown,
        /** "{repository}/edit/{branch}/{file}"; null: by the forge's own convention. */
        protected readonly ?string $editPattern = null,
    ) {
    }

    /**
     * @param callable(string $page, ?string $anchor): string $pageUrl  the address of a page of this manual and version
     * @param callable(string $file): string                  $assetUrl the address of a picture, by its path in the repository
     */
    public function render(Manual $manual, ManualVersion $version, DocPage $page, callable $pageUrl, callable $assetUrl): RenderedPage
    {
        $markdown = null !== $page->file ? (string) @file_get_contents($page->file) : '';
        $source = null !== $page->file ? $version->relative($page->file) : null;
        $pages = $this->manuals->pages($manual, $version);

        $html = $this->markdown->render($markdown);
        $html = $this->rewrite($html, $manual, $version, $pages, $source, $pageUrl, $assetUrl);

        return new RenderedPage(
            html: $html,
            headings: self::headings($html) ?: $this->markdown->extractHeadings($markdown),
            titled: 1 === preg_match('/<h1[\s>]/', $html),
            editUrl: null !== $source ? $this->forgeUrl($manual, $version, $source, 'edit') : null,
            sourceUrl: null !== $source ? $this->forgeUrl($manual, $version, $source, 'blob') : null,
            source: $source,
        );
    }

    /** Where a file of the repository is read (`blob`) or edited (`edit`) on its forge. */
    public function forgeUrl(Manual $manual, ManualVersion $version, string $file, string $action = 'blob'): ?string
    {
        if (null === $manual->repository) {
            return null;
        }
        $file = implode('/', array_map('rawurlencode', explode('/', ltrim($file, '/'))));
        $branch = implode('/', array_map('rawurlencode', explode('/', $version->branch)));

        if ('edit' === $action && null !== $this->editPattern) {
            return strtr($this->editPattern, ['{repository}' => $manual->repository, '{branch}' => $branch, '{file}' => $file]);
        }
        // GitLab keeps its actions behind "/-/".
        $infix = str_contains((string) parse_url($manual->repository, \PHP_URL_HOST), 'gitlab') ? '/-/' : '/';

        return $manual->repository.$infix.$action.'/'.$branch.'/'.$file;
    }

    /** @return list<array{level: int, text: string, id: string}> the rendered page's headings, with the ids they really carry */
    public static function headings(string $html): array
    {
        $headings = [];
        if (preg_match_all('#<h([2-4])(?:\s[^>]*)?>(.*?)</h\1>#s', $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (!preg_match('/\sid="([^"]+)"/', $m[0], $id)) {
                    continue;
                }
                $text = preg_replace('#<a\b[^>]*class="doc-anchor"[^>]*>.*?</a>#s', '', $m[2]) ?? $m[2];
                $headings[] = ['level' => (int) $m[1], 'text' => trim(html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5)), 'id' => html_entity_decode($id[1], \ENT_QUOTES | \ENT_HTML5)];
            }
        }

        return $headings;
    }

    protected function rewrite(string $html, Manual $manual, ManualVersion $version, DocumentationRegistry $pages, ?string $source, callable $pageUrl, callable $assetUrl): string
    {
        return preg_replace_callback(
            '#<(a|img)\b([^>]*?)\s(href|src)="([^"]*)"#',
            function (array $m) use ($manual, $version, $pages, $source, $pageUrl, $assetUrl): string {
                [$whole, $tag, $before, $attribute, $url] = $m;
                $url = html_entity_decode($url, \ENT_QUOTES | \ENT_HTML5);

                if ('' === $url || '#' === $url[0] || '/' === $url[0] || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
                    // Absolute: left alone; one that leaves the site opens without handing over the opener.
                    $external = 'a' === $tag && preg_match('#^https?://#i', $url) && !str_contains($before, 'rel=');

                    return $external ? '<a'.$before.' rel="noopener" '.$attribute.'="'.htmlspecialchars($url, \ENT_QUOTES).'"' : $whole;
                }

                $target = $this->resolve($url, $version, $pages, $source, 'img' === $tag, $manual, $pageUrl, $assetUrl);

                return null === $target ? $whole : '<'.$tag.$before.' '.$attribute.'="'.htmlspecialchars($target, \ENT_QUOTES).'"';
            },
            $html,
        ) ?? $html;
    }

    protected function resolve(string $url, ManualVersion $version, DocumentationRegistry $pages, ?string $source, bool $picture, Manual $manual, callable $pageUrl, callable $assetUrl): ?string
    {
        $anchor = null;
        if (false !== $hash = strpos($url, '#')) {
            $anchor = substr($url, $hash + 1);
            $url = substr($url, 0, $hash);
        }
        if (false !== $query = strpos($url, '?')) {
            $url = substr($url, 0, $query);
        }

        // Relative to the folder of the file the link is written in, inside the repository.
        $base = null !== $source && str_contains($source, '/') ? \dirname($source) : '';
        $file = self::normalize(('' !== $base ? $base.'/' : '').rawurldecode($url));
        if (null === $file) {
            return null; // climbs out of the repository
        }

        $absolute = rtrim($version->root, '/').'/'.$file;
        $candidates = [$absolute];
        if (is_dir($absolute)) {
            $candidates = [$absolute.'/index.md', $absolute.'/README.md'];
        } elseif (!is_file($absolute) && !str_contains(basename($file), '.')) {
            $candidates = [$absolute.'.md'];
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && null !== $page = $pages->findByFile($candidate)) {
                return $pageUrl($page->path, $anchor);
            }
        }

        // A folder of the manual without an index: its section (which opens on its first page).
        $docs = trim($version->docs, '/').'/';
        if (is_dir($absolute) && str_starts_with($file.'/', $docs) && null !== $pages->get(substr($file, \strlen($docs)))) {
            return $pageUrl(substr($file, \strlen($docs)), $anchor);
        }

        if (is_file($absolute) && \in_array(strtolower(pathinfo($file, \PATHINFO_EXTENSION)), self::PICTURES, true)) {
            return $assetUrl($file);
        }
        if ($picture) {
            return null;
        }

        $forge = $this->forgeUrl($manual, $version, $file, is_dir($absolute) ? 'tree' : 'blob');

        return null === $forge ? null : $forge.(null !== $anchor && '' !== $anchor ? '#'.$anchor : '');
    }

    /** "docs/../src/./Foo.php" → "src/Foo.php"; null when it leaves the repository. */
    public static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                if ([] === $parts) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
