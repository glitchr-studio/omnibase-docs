<?php

namespace Base\Wikidoc\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\Definition\Processor;

use Symfony\Component\DependencyInjection\ContainerBuilder;

use Base\Bundle\AbstractBaseExtension;
use Base\Wikidoc\Documentation\DocumentationRegistry;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Manual\PageRenderer;
use Base\Wikidoc\Search\BundleTypesenseClient;
use Base\Wikidoc\Search\ManualIndex;
use Base\Wikidoc\Search\TypesenseClientInterface;
use Base\Wikidoc\Search\TypesenseIndex;
use Base\Wikidoc\Site\Controller\ManualController;
use Symfony\Component\DependencyInjection\Reference;
use Typesense\Bundle\ORM\TypesenseManager;

class WikidocExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): WikidocConfiguration
    {
        return new WikidocConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // NB: package root, not src/Resources/config - this bundle has no
        // Resources directory. PhpFileLoader (not Xml): Symfony 8 removed
        // XmlFileLoader from dependency-injection.
        $loader = new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        $processor = new Processor();
        $configuration = new WikidocConfiguration();
        $config = $processor->processConfiguration($configuration, $configs);

        // Injected straight into the service: setConfiguration() below
        // recurses into every array it meets, so the roots MAP (whose keys
        // are root names and whose values are arrays) would be flattened
        // into wikidoc.roots.app.path-style scalar parameters instead of one
        // array argument. Pulled out before that runs.
        $roots = $config['roots'] ?? [];
        unset($config['roots']);

        // %kernel.project_dir% and friends are resolved here rather than
        // left for the service to interpret - a root is a plain path by the
        // time the registry sees it.
        foreach ($roots as $name => $root) {
            $roots[$name] = [
                'path' => $container->resolveEnvPlaceholders($this->resolveParameters($container, (string) $root['path']), true),
                'label' => $root['label'] ?? ucfirst((string) $name),
            ];
        }

        $container->getDefinition(DocumentationRegistry::class)->setArgument('$roots', $roots);

        $this->loadManuals($config, $container);
        unset($config['manuals'], $config['discover'], $config['discover_exclude']);

        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }

    /**
     * The public manuals. Their paths are handed to the registry as they are
     * written - an `%env(...)%` in one is resolved when the service is built,
     * not when the container is compiled - and the folders are looked at
     * when a page is asked for: a repository cloned, or a branch exported,
     * after the last deployment is simply there.
     *
     * @param array<string, mixed> $config
     */
    protected function loadManuals(array $config, ContainerBuilder $container): void
    {
        $registry = $container->getDefinition(ManualRegistry::class);
        $registry->setArgument('$configured', $config['manuals'] ?? []);
        $registry->setArgument('$discover', array_values($config['discover'] ?? []));
        $registry->setArgument('$options', [
            'export_dir' => $config['export_dir'],
            'branches' => $config['branches'],
            'docs' => $config['docs'],
            'readme' => \in_array($config['readme'], [false, 'false', '', null], true) ? false : $config['readme'],
            'remote' => $config['remote'],
            'cache_ttl' => $config['cache_ttl'],
            'exclude' => array_values($config['discover_exclude'] ?? []),
        ]);

        $container->getDefinition(ManualIndex::class)->setArgument('$ttl', $config['cache_ttl']);
        $container->getDefinition(PageRenderer::class)->setArgument('$editPattern', $config['edit_url']);

        // The engine: only when it is asked for AND glitchr/typesense-bundle
        // is there. Otherwise ManualSearch gets no TypesenseIndex, and the
        // local index answers.
        $typesense = $config['search']['typesense'];
        if ($typesense['enabled'] && class_exists(TypesenseManager::class)) {
            $container->register(BundleTypesenseClient::class, BundleTypesenseClient::class)
                ->setArguments([new Reference('typesense_manager'), $typesense['connection']]);
            $container->setAlias(TypesenseClientInterface::class, BundleTypesenseClient::class);
            $container->register(TypesenseIndex::class, TypesenseIndex::class)
                ->setArguments([new Reference(TypesenseClientInterface::class), new Reference(ManualIndex::class), $typesense['prefix']]);
        }

        if (!$config['public']['enabled']) {
            $container->removeDefinition(ManualController::class);
        }
    }

    protected function resolveParameters(ContainerBuilder $container, string $value): string
    {
        return (string) $container->getParameterBag()->resolveValue($value);
    }
}
