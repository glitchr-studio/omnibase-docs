<?php

namespace Base\Wikidoc\Tests\Manual;

use Base\Wikidoc\Documentation\MarkdownRenderer;
use Base\Wikidoc\Manual\ManualMarkdownRenderer;
use Base\Wikidoc\Manual\PageRenderer;
use Base\Wikidoc\Manual\RenderedPage;
use Base\Wikidoc\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class PageRendererTest extends TestCase
{
    /** @param array<string, mixed> $configured */
    private function render(string $manual, string $page, ?string $version = null, array $configured = [], ?string $editPattern = null, ?ManualMarkdownRenderer $markdown = null): RenderedPage
    {
        $registry = Fixtures::registry([], $configured);
        $found = $registry->get($manual);
        $at = $found->getVersion($version);

        return (new PageRenderer($registry, $markdown ?? new ManualMarkdownRenderer(), $editPattern))->render(
            $found,
            $at,
            $registry->pages($found, $at)->get($page),
            static fn (string $target, ?string $anchor = null): string => '/docs/'.$found->urlPath($at->name, $target).($anchor ? '#'.$anchor : ''),
            static fn (string $file): string => '/docs/_file/'.$found->urlPath($at->name, $file),
        );
    }

    public function testRelativeLinksGoToThePagesTheFilesBecame(): void
    {
        $html = $this->render('acme/widget', 'installation')->html;

        // A Markdown file of the manual: its page, in the same version.
        self::assertStringContainsString('<a href="/docs/acme/widget/current/20-guides/webhooks">configure the webhooks</a>', $html);
        // The README is the manual's home.
        self::assertStringContainsString('<a href="/docs/acme/widget/current">overview</a>', $html);
        // A file of the repository that is no page: on the forge, at the version's branch.
        self::assertStringContainsString('<a href="https://github.com/acme/widget/blob/current/src/Widget.php">the source</a>', $html);
        // A picture of the manual: served by the site.
        self::assertStringContainsString('<img src="/docs/_file/acme/widget/current/docs/img/flow.svg" alt="The flow"', $html);
        // An anchor and an absolute address are left alone (the latter opens without the opener).
        self::assertStringContainsString('<a href="#requirements">an anchor</a>', $html);
        self::assertStringContainsString('<a rel="noopener" href="https://example.org/widget">', $html);
    }

    public function testLinksAreRelativeToTheFileTheyAreWrittenIn(): void
    {
        // From the README, at the repository's root.
        $html = $this->render('acme/widget', '')->html;
        self::assertStringContainsString('<a href="/docs/acme/widget/current/installation">installation</a>', $html);
        self::assertStringContainsString('<a href="/docs/acme/widget/current/20-guides/webhooks#signature">webhooks</a>', $html);
        self::assertStringContainsString('<a href="https://github.com/acme/widget/blob/current/src/Widget.php">src/Widget.php</a>', $html);
        self::assertStringContainsString('href="https://github.com/acme/widget/blob/current/LICENSE"', $html);

        // From a page two folders down, with an anchor.
        $html = $this->render('acme/widget', '20-guides/webhooks')->html;
        self::assertStringContainsString('<a href="/docs/acme/widget/current/installation#configuration">installation</a>', $html);
    }

    public function testALinkToAFolderGoesToItsSection(): void
    {
        $html = (new ManualMarkdownRenderer())->render('[the guides](docs/20-guides/) and [the code](src/)');
        $registry = Fixtures::registry();
        $widget = $registry->get('acme/widget');
        $renderer = new class($registry, new ManualMarkdownRenderer()) extends PageRenderer {
            public function links(string $html, $manual, $version, $pages): string
            {
                return $this->rewrite($html, $manual, $version, $pages, 'README.md', static fn (string $p, ?string $a = null): string => '/docs/'.$p, static fn (string $f): string => '/file/'.$f);
            }
        };
        $html = $renderer->links($html, $widget, $widget->getDefaultVersion(), $registry->pages($widget));

        self::assertStringContainsString('<a href="/docs/20-guides">the guides</a>', $html);
        self::assertStringContainsString('<a href="https://github.com/acme/widget/tree/current/src">the code</a>', $html);
    }

    public function testEachVersionLinksInsideItself(): void
    {
        $rendered = $this->render('acme/widget', 'installation', '1.x');

        self::assertStringContainsString('The old way.', $rendered->html);
        self::assertSame('https://github.com/acme/widget/edit/1.x/docs/installation.md', $rendered->editUrl);
        self::assertSame('https://github.com/acme/widget/blob/1.x/docs/installation.md', $rendered->sourceUrl);
        self::assertSame('docs/installation.md', $rendered->source);
    }

    public function testEditThisPage(): void
    {
        self::assertSame('https://github.com/acme/widget/edit/current/docs/20-guides/webhooks.md', $this->render('acme/widget', '20-guides/webhooks')->editUrl);
        self::assertSame('https://github.com/acme/widget/edit/current/README.md', $this->render('acme/widget', '')->editUrl);
        // No known repository: no link.
        self::assertNull($this->render('acme/plain', '')->editUrl);
        // GitLab keeps its actions behind /-/.
        $gitlab = ['acme/widget' => ['path' => Fixtures::REPOS.'/acme/widget', 'repository' => 'https://gitlab.example.org/acme/widget']];
        self::assertSame('https://gitlab.example.org/acme/widget/-/edit/current/docs/installation.md', $this->render('acme/widget', 'installation', null, $gitlab)->editUrl);
        // A site's own pattern.
        self::assertSame('https://forge.example.org/edit?repo=https://github.com/acme/widget&ref=current&file=docs/installation.md', $this->render('acme/widget', 'installation', null, [], 'https://forge.example.org/edit?repo={repository}&ref={branch}&file={file}')->editUrl);
    }

    public function testHeadingsCarryTheIdsThePageReallyHas(): void
    {
        $rendered = $this->render('acme/widget', 'installation');

        self::assertTrue($rendered->titled);
        self::assertSame([
            ['level' => 2, 'text' => 'Requirements', 'id' => 'requirements'],
            ['level' => 2, 'text' => 'Configuration', 'id' => 'configuration'],
        ], $rendered->headings);
        self::assertStringContainsString('id="requirements"', $rendered->html);
        // The front matter is metadata, not text.
        self::assertStringNotContainsString('order: 10', $rendered->html);
    }

    public function testCalloutsAreWrittenAsGitHubWritesThem(): void
    {
        $markdown = (new ManualMarkdownRenderer())->setLabels(['note' => 'Note', 'warning' => 'Attention']);
        $html = $this->render('acme/widget', 'installation', null, [], null, $markdown)->html;

        self::assertMatchesRegularExpression('#<blockquote class="doc-callout doc-callout-note" data-callout="note" data-label="Note">\s*<p>The widget needs PHP 8\.2\.</p>\s*</blockquote>#', $html);
        self::assertMatchesRegularExpression('#<blockquote class="doc-callout doc-callout-warning" data-callout="warning" data-label="Attention">\s*<p>Never commit the signing secret\.</p>#', $html);
        self::assertStringNotContainsString('[!NOTE]', $html);
        // A quote that is not a callout stays one.
        self::assertMatchesRegularExpression('#<blockquote>\s*<p>An ordinary quote stays a quote\.</p>#', $html);
    }

    public function testACalloutWithItsTextOnTheMarkersLine(): void
    {
        $html = (new ManualMarkdownRenderer())->render("> [!TIP] Use the sandbox first.\n> It costs nothing.\n");

        self::assertStringContainsString('class="doc-callout doc-callout-tip"', $html);
        self::assertStringContainsString('data-label="Tip"', $html);
        self::assertStringContainsString('<p>Use the sandbox first.', $html);
    }

    public function testCodeBlocksAreFramedAndNamed(): void
    {
        $html = $this->render('acme/widget', 'installation')->html;

        self::assertStringContainsString('<div class="doc-code" data-lang="yaml"><pre class="notranslate"><code class="language-yaml">', $html);
        self::assertStringContainsString('<div class="doc-code" data-lang="sh">', $html);
        self::assertStringContainsString('<div class="doc-code" data-lang="php">', $html);
        // The code is there whatever colours it: tags stripped, it reads as it was written.
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5);
        self::assertStringContainsString('composer require acme/widget', $text);
        self::assertStringContainsString("secret: '%env(WIDGET_SECRET)%'", $text);
        self::assertStringContainsString("\$widget = new Widget(secret: 'not-a-real-secret');", $text);

        if (ManualMarkdownRenderer::canHighlight()) {
            self::assertStringContainsString('class="hl-', $html);
        } else {
            self::assertStringNotContainsString('class="hl-', $html);
        }
    }

    public function testAFenceWithoutALanguageAndMarkupInCode(): void
    {
        $html = (new ManualMarkdownRenderer())->render("```\n<script>alert(1)</script>\n```\n\n```html\n<b>bold</b>\n```\n");

        self::assertStringContainsString('<div class="doc-code"><pre class="notranslate"><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre></div>', $html);
        self::assertStringNotContainsString('<b>bold</b>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testTheBackOfficesRendererIsUntouched(): void
    {
        // What chapaland and La Touche Originale render: no callout, no frame.
        $html = (new MarkdownRenderer())->render("> [!NOTE]\n> Plain.\n\n```php\necho 1;\n```\n");

        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('[!NOTE]', $html);
        self::assertStringContainsString('<pre><code class="language-php">echo 1;', $html);
        self::assertStringNotContainsString('doc-code', $html);
        self::assertStringNotContainsString('doc-callout', $html);
    }

    public function testAPathNeverLeavesTheRepository(): void
    {
        self::assertSame('src/Foo.php', PageRenderer::normalize('docs/../src/./Foo.php'));
        self::assertSame('', PageRenderer::normalize('docs/..'));
        self::assertNull(PageRenderer::normalize('../../etc/passwd'));
        self::assertNull(PageRenderer::normalize('docs/../../secret'));
    }
}
