<?php

namespace Base\Wikidoc\Tests\DependencyInjection;

use Base\Wikidoc\Controller\Backend\ManualController as BackOfficeManualController;
use Base\Wikidoc\DependencyInjection\WikidocConfiguration;
use Base\Wikidoc\DependencyInjection\WikidocExtension;
use Base\Wikidoc\Documentation\DocumentationRegistry;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Search\ManualSearch;
use Base\Wikidoc\Search\TypesenseIndex;
use Base\Wikidoc\Site\Controller\ManualController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Attribute\Route;
use Typesense\Bundle\ORM\TypesenseManager;

final class ExtensionTest extends TestCase
{
    /** What chapaland and La Touche Originale have: the back office's roots, or nothing at all. */
    private const BEFORE = ['roots' => [
        'base' => ['path' => '%kernel.project_dir%/vendor/glitchr/omnibase/docs', 'label' => 'Base'],
        'app' => ['path' => '%kernel.project_dir%/docs', 'label' => 'Application'],
    ]];

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/srv/app');
        (new WikidocExtension())->load([$config], $container);

        return $container;
    }

    public function testASiteThatSaysNothingNewGetsNothingNew(): void
    {
        foreach ([self::BEFORE, []] as $config) {
            $container = $this->load($config);

            self::assertFalse($container->hasDefinition(ManualController::class), 'no public controller');
            self::assertFalse($container->hasDefinition(TypesenseIndex::class), 'no engine');
            self::assertFalse($container->getParameter('wikidoc.public.enabled'));
            self::assertFalse($container->getParameter('wikidoc.search.typesense.enabled'));
            self::assertSame([], $container->getDefinition(ManualRegistry::class)->getArgument('$configured'));
            self::assertSame([], $container->getDefinition(ManualRegistry::class)->getArgument('$discover'));
        }

        // The back office's manual is wired as it was.
        $container = $this->load(self::BEFORE);
        self::assertSame([
            'base' => ['path' => '/srv/app/vendor/glitchr/omnibase/docs', 'label' => 'Base'],
            'app' => ['path' => '/srv/app/docs', 'label' => 'Application'],
        ], $container->getDefinition(DocumentationRegistry::class)->getArgument('$roots'));
        if (class_exists(\Base\Admin\Context\AdminContext::class)) {
            self::assertTrue($container->hasDefinition(BackOfficeManualController::class));
        }
    }

    public function testThePublicControllerIsOutOfTheFolderTheOlderSitesImport(): void
    {
        // They import @WikidocBundle/src/Controller, whole: nothing public may live there.
        $folder = \dirname((new \ReflectionClass(BackOfficeManualController::class))->getFileName(), 2);
        self::assertStringEndsWith('/src/Controller', $folder);
        self::assertStringNotContainsString($folder.'/', (new \ReflectionClass(ManualController::class))->getFileName());

        $routes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            preg_match_all("/name:\s*'([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
            $routes = array_merge($routes, $m[1]);
        }
        sort($routes);
        self::assertSame(['backoffice_manual', 'backoffice_manual_search'], $routes);

        // The public routes, under the configurable prefix.
        $names = [];
        foreach ((new \ReflectionClass(ManualController::class))->getMethods() as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $names[$attribute->getArguments()['name']] = $attribute->getArguments()[0];
            }
        }
        self::assertSame([
            'wikidoc_index' => '/%wikidoc.public.path%',
            'wikidoc_search' => '/%wikidoc.public.path%/_search',
            'wikidoc_asset' => '/%wikidoc.public.path%/_file/{path}',
            'wikidoc_manual' => '/%wikidoc.public.path%/{path}',
        ], $names);
    }

    public function testThePublicManualsOnDemand(): void
    {
        $container = $this->load(self::BEFORE + [
            'manuals' => ['glitchr/omnitrade' => ['path' => '%env(OMNI_REPOS)%/omnitrade/core', 'versions' => ['1.x' => '/srv/exports/1.x']]],
            'discover' => ['%env(OMNI_REPOS)%/*/*'],
            'discover_exclude' => ['acme/*'],
            'cache_ttl' => 120,
            'public' => ['enabled' => true, 'path' => 'manuals'],
            'search' => ['typesense' => ['enabled' => true, 'prefix' => 'omni']],
        ]);

        self::assertTrue($container->hasDefinition(ManualController::class));
        self::assertSame('manuals', $container->getParameter('wikidoc.public.path'));

        $registry = $container->getDefinition(ManualRegistry::class);
        // The package's name is kept as written; the env placeholder is left for the running container.
        self::assertSame(['glitchr/omnitrade'], array_keys($registry->getArgument('$configured')));
        self::assertSame(['1.x' => ['path' => '/srv/exports/1.x', 'branch' => null]], $registry->getArgument('$configured')['glitchr/omnitrade']['versions']);
        self::assertSame(['%env(OMNI_REPOS)%/*/*'], $registry->getArgument('$discover'));
        self::assertSame(120, $registry->getArgument('$options')['cache_ttl']);
        self::assertSame(['acme/*'], $registry->getArgument('$options')['exclude']);
        self::assertSame('/srv/app/var/wikidoc', $container->getParameterBag()->resolveValue($registry->getArgument('$options')['export_dir']));

        if (class_exists(TypesenseManager::class)) {
            self::assertTrue($container->hasDefinition(TypesenseIndex::class));
            self::assertSame('omni', $container->getDefinition(TypesenseIndex::class)->getArgument(2));
        } else {
            self::assertFalse($container->hasDefinition(TypesenseIndex::class), 'asked for, but glitchr/typesense-bundle is not installed: the local index');
        }
        // Either way the search is there, and takes the engine only if there is one.
        self::assertEquals(new Reference(TypesenseIndex::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE), $container->getDefinition(ManualSearch::class)->getArgument(2));
    }

    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new WikidocConfiguration(), []);

        self::assertSame([], $config['roots']);
        self::assertSame([], $config['manuals']);
        self::assertSame([], $config['discover']);
        self::assertSame(['enabled' => false, 'path' => 'docs'], $config['public']);
        self::assertSame(['enabled' => false, 'connection' => null, 'prefix' => 'wikidoc'], $config['search']['typesense']);
        self::assertSame('docs', $config['docs']);
        self::assertSame('README.md', $config['readme']);
        self::assertSame(0, $config['cache_ttl']);
    }

    public function testAPublicPathWithSlashesAroundIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new WikidocConfiguration(), [['public' => ['path' => '/docs/']]]);
    }
}
