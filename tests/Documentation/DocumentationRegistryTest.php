<?php

namespace Base\Wikidoc\Tests\Documentation;

use Base\Wikidoc\Documentation\DocumentationRegistry;
use Base\Wikidoc\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The registry the back office's manual reads: what it did before the
 * public manuals it still does, and what was added for them.
 */
final class DocumentationRegistryTest extends TestCase
{
    private const WIDGET = Fixtures::REPOS.'/acme/widget';

    public function testRootsAsBefore(): void
    {
        $registry = new DocumentationRegistry([
            'base' => ['path' => self::WIDGET.'/docs', 'label' => 'Base'],
            'app' => ['path' => Fixtures::EXPORT.'/acme--widget/1.x/docs', 'label' => 'Application'],
            'missing' => ['path' => '/nowhere', 'label' => 'Missing'],
        ]);

        // No index.md in either root: no home page, the first page in the tree's order is the default.
        self::assertNull($registry->get(''));
        self::assertSame('20-guides/events', $registry->getDefault()->path, 'the tree is ordered: the folder "20-" comes before a page without a number');
        // The later root overrides the same path, and says what it replaced.
        self::assertSame('Installing the widget (1.x)', $registry->get('installation')->title);
        self::assertTrue($registry->get('installation')->isOverride());
        self::assertSame('app', $registry->get('installation')->root);
        // A folder without index.md is a section; its pages are ordered by front matter, then by title.
        $guides = $registry->get('20-guides');
        self::assertTrue($guides->isSection());
        self::assertSame('Guides', $guides->title);
        self::assertSame(['20-guides/events', '20-guides/webhooks'], array_map(static fn ($p) => $p->path, $guides->children));
        self::assertCount(3, $registry->getAllPages());
    }

    public function testAHomeFileOutsideTheFolder(): void
    {
        $registry = new DocumentationRegistry(['docs' => ['path' => self::WIDGET.'/docs', 'label' => 'Widget', 'home' => self::WIDGET.'/README.md']]);

        self::assertSame('Acme Widget', $registry->get('')->title);
        self::assertSame('', $registry->getDefault()->path);
        self::assertSame(['installation', '20-guides'], array_map(static fn ($p) => $p->path, $registry->getTree()), 'the home page is not one of its own children');
        self::assertNotSame(
            (new DocumentationRegistry(['docs' => ['path' => self::WIDGET.'/docs', 'label' => 'Widget']]))->getFingerprint(),
            $registry->getFingerprint(),
            'the README is part of the fingerprint',
        );

        // A home file that is not there changes nothing.
        $registry = new DocumentationRegistry(['docs' => ['path' => self::WIDGET.'/docs', 'label' => 'Widget', 'home' => self::WIDGET.'/NOPE.md']]);
        self::assertNull($registry->get(''));
    }

    public function testReadingOrderNeighboursAndAncestors(): void
    {
        $registry = new DocumentationRegistry(['docs' => ['path' => self::WIDGET.'/docs', 'label' => 'Widget', 'home' => self::WIDGET.'/README.md']]);

        self::assertSame(['', 'installation', '20-guides/events', '20-guides/webhooks'], array_map(static fn ($p) => $p->path, $registry->getSequence()));

        [$previous, $next] = $registry->getNeighbours($registry->get('installation'));
        self::assertSame(['', '20-guides/events'], [$previous->path, $next->path]);
        [$previous, $next] = $registry->getNeighbours($registry->get(''));
        self::assertNull($previous);
        self::assertSame('installation', $next->path);
        [, $next] = $registry->getNeighbours($registry->get('20-guides/webhooks'));
        self::assertNull($next);

        self::assertSame(['20-guides'], array_map(static fn ($p) => $p->path, $registry->getAncestors($registry->get('20-guides/webhooks'))));
        self::assertSame([], $registry->getAncestors($registry->get('installation')));

        self::assertSame('20-guides/webhooks', $registry->findByFile(self::WIDGET.'/docs/20-guides/webhooks.md')->path);
        self::assertSame('', $registry->findByFile(self::WIDGET.'/README.md')->path);
        self::assertNull($registry->findByFile(self::WIDGET.'/src/Widget.php'));
    }
}
