<?php

namespace Base\Wikidoc\Documentation;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown -> HTML for the manual, plus the plain-text extraction the
 * search index is built from.
 *
 * HTML input is NOT allowed through. These files are technical documentation
 * kept in the repository, so raw HTML would only be there to do something
 * markdown cannot - and the one thing it reliably does is turn a docs file
 * into a script injection on an authenticated admin page.
 */
class MarkdownRenderer
{
    protected ?MarkdownConverter $converter = null;

    public function render(string $markdown): string
    {
        return (string) $this->converter()->convert($markdown);
    }

    public function renderFile(string $file): string
    {
        return $this->render((string) @file_get_contents($file));
    }

    protected function converter(): MarkdownConverter
    {
        if (null !== $this->converter) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'html_class' => 'doc-anchor',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'symbol' => '#',
                'insert' => 'after',
                'min_heading_level' => 2,
                'max_heading_level' => 4,
                'aria_hidden' => true,
            ],
            'table_of_contents' => [
                'html_class' => 'doc-toc',
                'position' => 'placeholder',
                'placeholder' => '[TOC]',
                'style' => 'bullet',
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        // Front matter is metadata for the registry (title/order) - strip it
        // from the rendered body rather than printing it as a paragraph.
        $environment->addExtension(new FrontMatterExtension());
        $environment->addExtension(new AttributesExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());
        $this->configure($environment);

        return $this->converter = new MarkdownConverter($environment);
    }

    /**
     * For a renderer that reads more than this one: its own extensions and
     * listeners, added last. Nothing here - the back office's manual renders
     * as it always did (see Base\Wikidoc\Manual\ManualMarkdownRenderer).
     */
    protected function configure(Environment $environment): void
    {
    }

    /**
     * The headings of a document, for the search index and the "on this
     * page" rail. Read from the markdown source rather than the rendered
     * HTML - cheaper, and it cannot pick up headings that only exist inside
     * a fenced code block, which a naive HTML scan would.
     *
     * @return array<int, array{level: int, text: string, id: string}>
     */
    public function extractHeadings(string $markdown): array
    {
        $headings = [];
        $inFence = false;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }

            if (preg_match('/^(#{2,4})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $text = trim($m[2]);
                $headings[] = [
                    'level' => \strlen($m[1]),
                    'text' => $text,
                    'id' => $this->slugify($text),
                ];
            }
        }

        return $headings;
    }

    /**
     * Readable plain text for the search index: markdown syntax removed, so
     * a query matches what the reader actually sees rather than the
     * punctuation around it.
     */
    public function extractText(string $markdown): string
    {
        $text = preg_replace('/^---\R.*?^---\R/ms', '', $markdown) ?? $markdown;   // front matter
        // Code is KEPT, only its fences removed. In technical documentation
        // the code spans hold exactly what people search for - a path, a
        // command, a class name - so dropping them made the index miss the
        // most specific query anyone would type ("src", "make deploy").
        $text = preg_replace('/```[a-z0-9]*\R?(.*?)```/s', '$1 ', $text) ?? $text;  // fenced code
        $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;                  // inline code
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;        // images
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;      // links -> label
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;           // heading marks
        $text = preg_replace('/^\s{0,3}>\s?/m', '', $text) ?? $text;                // quotes
        $text = preg_replace('/[*_~]{1,3}/', '', $text) ?? $text;                   // emphasis
        $text = preg_replace('/^\s{0,3}([-*+]|\d+\.)\s+/m', '', $text) ?? $text;    // list bullets
        $text = preg_replace('/\|/', ' ', $text) ?? $text;                          // table pipes
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Must match HeadingPermalink's own slugger, or every search result
     * would link to an anchor that does not exist on the page.
     */
    public function slugify(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
