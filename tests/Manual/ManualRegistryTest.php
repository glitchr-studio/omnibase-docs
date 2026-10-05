<?php

namespace Base\Wikidoc\Tests\Manual;

use Base\Wikidoc\Manual\Manual;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class ManualRegistryTest extends TestCase
{
    public function testEveryFolderWithAPackageAndSomethingToReadIsAManual(): void
    {
        $manuals = Fixtures::registry()->all();

        // "empty" has a composer.json and nothing to read: no manual.
        self::assertSame(['acme/plain', 'acme/script', 'acme/widget'], array_keys($manuals));
        self::assertSame('@acme/script', $manuals['acme/script']->name);
        self::assertSame('A widget, for the tests of omnibase/docs.', $manuals['acme/widget']->description);
    }

    public function testNoConfigurationNoManual(): void
    {
        self::assertSame([], (new ManualRegistry())->all());
        self::assertNull((new ManualRegistry())->resolve('acme/widget'));
    }

    public function testAManualIsFoundByItsPackageNameWhateverItsSpelling(): void
    {
        $registry = Fixtures::registry();

        self::assertSame('acme/widget', $registry->get('Acme/Widget')?->name);
        self::assertSame('@acme/script', $registry->get('@acme/script')?->name);
        self::assertSame('@acme/script', $registry->get('acme/script')?->name);
        self::assertNull($registry->get('acme/empty'));
    }

    public function testTheWorkingTreeIsTheDefaultVersionAndTheExportsFollow(): void
    {
        $widget = Fixtures::registry()->get('acme/widget');

        self::assertSame(['current', '1.x'], array_keys($widget->versions));
        self::assertTrue($widget->versions['current']->live);
        self::assertFalse($widget->versions['1.x']->live);
        self::assertSame('current', $widget->getDefaultVersion()->name);
        self::assertSame('1.x', $widget->getVersion('1.x')->name);
        self::assertSame('current', $widget->getVersion('current')->name);
        self::assertNull($widget->getVersion('9.x'));
    }

    public function testTheReadmeIsTheHomePageUnlessTheDocsHaveAnIndex(): void
    {
        $registry = Fixtures::registry();

        $widget = $registry->pages($registry->get('acme/widget'));
        self::assertSame('Acme Widget', $widget->get('')->title);
        self::assertNull($widget->get('readme'));
        self::assertSame('Installation', $widget->get('installation')->title);

        // docs/index.md is the home; the README stays readable, first in the contents.
        $script = $registry->pages($registry->get('acme/script'));
        self::assertSame('Script', $script->get('')->title);
        self::assertSame('@acme/script', $script->get('readme')->title);
        self::assertSame(['readme', 'options'], array_map(static fn ($p) => $p->path, $script->getTree()));

        // A README alone is a manual of one page.
        $plain = $registry->pages($registry->get('acme/plain'));
        self::assertSame('Acme Plain', $plain->getDefault()->title);
        self::assertSame([], $plain->getTree());
    }

    public function testEachVersionHasItsOwnPages(): void
    {
        $registry = Fixtures::registry();
        $widget = $registry->get('acme/widget');

        self::assertSame('Installing the widget (1.x)', $registry->pages($widget, $widget->getVersion('1.x'))->get('installation')->title);
        self::assertNull($registry->pages($widget, $widget->getVersion('1.x'))->get('20-guides/webhooks'));
        self::assertNotNull($registry->pages($widget, $widget->getVersion('current'))->get('20-guides/webhooks'));
    }

    public function testAnAddressNamesAManualAVersionAndAPage(): void
    {
        $registry = Fixtures::registry();

        $location = $registry->resolve('acme/widget/1.x/installation');
        self::assertSame(['acme/widget', '1.x', 'installation', true], [$location->manual->name, $location->version->name, $location->path, $location->explicit]);

        // No version, or "current": the default one, and the address says it was not spelled out.
        $location = $registry->resolve('acme/widget/20-guides/webhooks');
        self::assertSame(['current', '20-guides/webhooks', false], [$location->version->name, $location->path, $location->explicit]);
        $location = $registry->resolve('acme/widget/current');
        self::assertSame(['current', '', false], [$location->version->name, $location->path, $location->explicit]);

        self::assertSame('', $registry->resolve('/acme/script/')->path);
        self::assertNull($registry->resolve('acme/unknown/1.x'));
        self::assertNull($registry->resolve(''));
    }

    public function testAConfiguredManualWinsOverADiscoveredOneAndAMissingFolderIsSkipped(): void
    {
        $registry = Fixtures::registry([], [
            'acme/widget' => ['path' => Fixtures::REPOS.'/acme/widget', 'label' => 'The Widget', 'repository' => 'https://gitlab.example.org/acme/widget/', 'default_version' => '1.x'],
            'acme/gone' => ['path' => Fixtures::REPOS.'/acme/gone'],
        ]);

        $widget = $registry->get('acme/widget');
        self::assertSame('The Widget', $widget->label);
        self::assertSame('https://gitlab.example.org/acme/widget', $widget->repository);
        self::assertSame('1.x', $widget->getDefaultVersion()->name);
        self::assertNull($registry->get('acme/gone'));
    }

    public function testVersionsWithACheckoutOfTheirOwn(): void
    {
        $registry = Fixtures::registry([], [
            'acme/widget' => ['path' => Fixtures::REPOS.'/acme/widget', 'versions' => [
                '2.x' => ['path' => Fixtures::REPOS.'/acme/widget'],
                '1.x' => ['path' => Fixtures::EXPORT.'/acme--widget/1.x', 'branch' => 'legacy'],
            ]],
        ], []);

        $widget = $registry->get('acme/widget');
        self::assertSame(['2.x', '1.x'], array_keys($widget->versions));
        self::assertSame('legacy', $widget->versions['1.x']->branch);
    }

    public function testTheRepositoryComesFromThePackageWhenThereIsNoGitFolder(): void
    {
        $registry = Fixtures::registry();

        self::assertSame('https://github.com/acme/widget', $registry->get('acme/widget')->repository);
        self::assertSame('https://github.com/acme/script', $registry->get('acme/script')->repository);
        self::assertNull($registry->get('acme/plain')->repository);
    }

    public function testExcludedPackagesAreNotDiscovered(): void
    {
        self::assertSame(['acme/widget'], array_keys(Fixtures::registry(['exclude' => ['acme/plain', '@acme/*']])->all()));
    }

    public function testNewestVersionFirst(): void
    {
        $versions = ['1.x', '2.0', 'main', '3.x', '2.x'];
        usort($versions, [ManualRegistry::class, 'compareVersions']);

        self::assertSame(['main', '3.x', '2.x', '2.0', '1.x'], $versions);
        self::assertTrue(Fixtures::registry()->isVersionBranch('2.x'));
        self::assertTrue(Fixtures::registry()->isVersionBranch('main'));
        self::assertFalse(Fixtures::registry()->isVersionBranch('feature/search'));
    }

    public function testTheAddressOfAPage(): void
    {
        $widget = Fixtures::registry()->get('acme/widget');

        self::assertSame('acme/widget/current', $widget->urlPath());
        self::assertSame('acme/widget/1.x/installation', $widget->urlPath('1.x', 'installation'));
        self::assertSame('acme/script', Manual::keyOf('@Acme/Script'));
    }
}
