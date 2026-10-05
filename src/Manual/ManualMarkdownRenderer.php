<?php

namespace Base\Wikidoc\Manual;

use Base\Wikidoc\Documentation\MarkdownRenderer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

/**
 * The public manuals' Markdown: what the back office's renderer reads, plus
 *
 *  - callouts, written as GitHub writes them - a quote whose first line is
 *    `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]` or `[!CAUTION]` - so a
 *    page reads the same on github.com and here;
 *  - code blocks in a frame that names their language, coloured on the
 *    server when tempest/highlight is installed (no script, no flash), plain
 *    otherwise.
 *
 * A subclass, so that the back office's manual does not change by a byte.
 */
class ManualMarkdownRenderer extends MarkdownRenderer
{
    public const CALLOUTS = ['note' => 'Note', 'tip' => 'Tip', 'important' => 'Important', 'warning' => 'Warning', 'caution' => 'Caution'];

    /** What a fence may be called → the name the highlighter knows it by. */
    protected const LANGUAGES = ['shell' => 'bash', 'sh' => 'bash', 'console' => 'bash', 'zsh' => 'bash', 'yml' => 'yaml', 'javascript' => 'js', 'htm' => 'html', 'env' => 'dotenv', 'jinja' => 'twig', 'plain' => 'text', 'txt' => 'text'];

    /** @var array<string, string> */
    protected array $labels = self::CALLOUTS;

    /**
     * The callouts' titles, in the reader's language ("Note", "Astuce"...).
     *
     * @param array<string, string> $labels
     */
    public function setLabels(array $labels): static
    {
        $labels = array_intersect_key($labels, self::CALLOUTS) + self::CALLOUTS;
        if ($labels !== $this->labels) {
            $this->labels = $labels;
            $this->converter = null;
        }

        return $this;
    }

    public static function canHighlight(): bool
    {
        return class_exists(\Tempest\Highlight\Highlighter::class);
    }

    protected function configure(Environment $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            $quotes = [];
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof BlockQuote) {
                    $quotes[] = $node;
                }
            }
            foreach ($quotes as $quote) {
                $this->callout($quote);
            }
        });

        $environment->addRenderer(FencedCode::class, new class(self::LANGUAGES) implements NodeRendererInterface {
            private ?object $highlighter = null;

            /** @param array<string, string> $aliases */
            public function __construct(private readonly array $aliases)
            {
            }

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                \assert($node instanceof FencedCode);

                $language = strtolower(preg_replace('/[^A-Za-z0-9_+-].*$/', '', $node->getInfoWords()[0] ?? '') ?? '');
                $code = rtrim($node->getLiteral(), "\n");
                $body = null;

                if ('' !== $language && ManualMarkdownRenderer::canHighlight()) {
                    try {
                        $this->highlighter ??= new \Tempest\Highlight\Highlighter();
                        $body = $this->highlighter->parse($code, $this->aliases[$language] ?? $language);
                    } catch (\Throwable) {
                        $body = null; // a language it stumbles on: shown plain
                    }
                }
                $body ??= Xml::escape($code);

                return '<div class="doc-code"'.('' !== $language ? ' data-lang="'.Xml::escape($language).'"' : '').'>'
                    .'<pre class="notranslate"><code'.('' !== $language ? ' class="language-'.Xml::escape($language).'"' : '').'>'.$body.'</code></pre>'
                    .'</div>';
            }
        }, 20);
    }

    /**
     * `> [!NOTE]` on the quote's first line: the marker goes, the quote
     * becomes a callout (class and title on the element itself).
     */
    protected function callout(BlockQuote $quote): void
    {
        $paragraph = $quote->firstChild();
        if (!$paragraph instanceof Paragraph) {
            return;
        }

        // The marker's characters may have been split into several text nodes.
        $line = '';
        $nodes = [];
        for ($node = $paragraph->firstChild(); $node instanceof Text; $node = $node->next()) {
            $line .= $node->getLiteral();
            $nodes[] = $node;
        }
        if (!preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*/i', $line, $m)) {
            return;
        }

        $type = strtolower($m[1]);
        $rest = substr($line, \strlen($m[0]));
        $after = end($nodes)->next();
        foreach ($nodes as $i => $node) {
            if (0 === $i && '' !== $rest) {
                $node->setLiteral($rest);
            } else {
                $node->detach();
            }
        }
        if ('' === $rest && $after instanceof Newline) {
            $after->detach();
        }
        if (null === $paragraph->firstChild()) {
            $paragraph->detach();
        }

        $quote->data->set('attributes/class', 'doc-callout doc-callout-'.$type);
        $quote->data->set('attributes/data-callout', $type);
        $quote->data->set('attributes/data-label', $this->labels[$type] ?? ucfirst($type));
    }
}
