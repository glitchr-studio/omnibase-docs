<?php

namespace Base\Wikidoc\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use Base\Bundle\AbstractBaseConfiguration;

class WikidocConfiguration extends AbstractBaseConfiguration
{
    /**
     * getTreeBuilder() memoises the builder while this method ADDS children
     * to it, so a second call would redeclare them - see the same guard on
     * Base\Admin's configuration.
     */
    private bool $childrenDeclared = false;

    /**
     * @inheritdoc
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                // Documentation roots, LOWEST priority first. A page is
                // identified by its path within a root, so a later root
                // providing the same path replaces the earlier one - the
                // same "drop a file at the same path" override rule Symfony
                // already uses for bundle templates.
                //
                //     wikidoc:
                //         roots:
                //             base: { path: '%kernel.project_dir%/vendor/glitchr/omnibase/docs', label: 'Base' }
                //             app:  { path: '%kernel.project_dir%/docs', label: 'Application' }
                //
                // A root that does not exist on disk is skipped, so shipping
                // this config before writing any docs is harmless.
                ->arrayNode('roots')
                    ->info('Documentation roots, lowest priority first; a later root overrides same-path pages.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('label')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()

                // ---- The public manuals (docs/manuals.md) --------------
                // Everything below is new in 2.0 and off by default: a site
                // that sets none of it keeps the back office's manual and
                // nothing else.
                //
                //     wikidoc:
                //         manuals:
                //             glitchr/omnitrade: { path: '%env(OMNI_REPOS)%/omnitrade/core' }
                //         discover: ['%env(OMNI_REPOS)%/*/*']
                //         public: { enabled: true }
                //         search: { typesense: { enabled: true } }
                ->arrayNode('manuals')
                    ->info('The manuals: one per package, read in the docs/ folder and the README of its repository. Keyed by the package\'s name.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('path')->isRequired()->cannotBeEmpty()->info('The repository\'s checkout.')->end()
                            ->scalarNode('label')->defaultNull()->end()
                            ->scalarNode('description')->defaultNull()->end()
                            ->scalarNode('docs')->defaultNull()->info('The Markdown folder inside the repository (default: wikidoc.docs).')->end()
                            ->scalarNode('readme')->defaultNull()->info('The README inside the repository; false: none (default: wikidoc.readme).')->end()
                            ->scalarNode('repository')->defaultNull()->info('Its web address, for "Edit this page". Default: the checkout\'s origin remote.')->end()
                            ->scalarNode('default_version')->defaultNull()->end()
                            ->arrayNode('versions')
                                ->info('Versions with a checkout of their own: {"2.x": {path: ..., branch: "2.x"}}. Default: the branch checked out, and those wikidoc:sync exported.')
                                ->useAttributeAsKey('name')
                                ->normalizeKeys(false)
                                ->arrayPrototype()
                                    ->beforeNormalization()->ifString()->then(static fn (string $path): array => ['path' => $path])->end()
                                    ->children()
                                        ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                                        ->scalarNode('branch')->defaultNull()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('discover')
                    ->info('Glob patterns: every folder matched that holds a composer.json or a package.json, and a docs/ folder or a README, is a manual named after its package.')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('discover_exclude')
                    ->info('Package names (or folders) left out of the discovery; fnmatch patterns.')
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('docs')->defaultValue('docs')->info('The Markdown folder inside a repository.')->end()
                ->scalarNode('readme')->defaultValue('README.md')->info('The README inside a repository; false: none.')->end()
                ->scalarNode('remote')->defaultValue('origin')->info('The git remote a repository is published on.')->end()
                ->scalarNode('branches')->defaultValue('/^(\d+\.(x|\d+)|main|master)$/')->info('The branches that are versions.')->end()
                ->scalarNode('export_dir')->defaultValue('%kernel.project_dir%/var/wikidoc')->info('Where wikidoc:sync copies the branches that are not checked out.')->end()
                ->scalarNode('edit_url')->defaultNull()->info('"{repository}/edit/{branch}/{file}"; null: the forge\'s own convention.')->end()
                ->integerNode('cache_ttl')->defaultValue(0)->min(0)->info('Seconds the discovered folders and the local search records are kept; 0: read again at each request.')->end()
                ->arrayNode('public')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('Registers the public controller (import @WikidocBundle/src/Site/Controller in the routes).')->end()
                        ->scalarNode('path')->defaultValue('docs')->info('The address the manuals are published under.')
                            ->validate()->ifTrue(static fn ($v): bool => !\is_string($v) || 1 !== preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $v))->thenInvalid('wikidoc.public.path is a path without slashes around it, %s given.')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('search')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('typesense')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->info('Needs glitchr/typesense-bundle; without it, or with the server down, the local index answers.')->end()
                                ->scalarNode('connection')->defaultNull()->info('The typesense-bundle connection; null: its default one.')->end()
                                ->scalarNode('prefix')->defaultValue('wikidoc')->info('The collections are <prefix>_<manual>_<version>.')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
