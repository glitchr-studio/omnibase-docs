<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Base\Wikidoc\Controller\Backend\ManualController;
use Base\Wikidoc\Documentation\DocumentationRegistry;
use Base\Wikidoc\Documentation\MarkdownRenderer;
use Base\Wikidoc\Documentation\SearchIndexBuilder;
use Base\Wikidoc\Command\IndexCommand;
use Base\Wikidoc\Command\SyncCommand;
use Base\Wikidoc\Manual\BranchExporter;
use Base\Wikidoc\Manual\ManualMarkdownRenderer;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Manual\PageRenderer;
use Base\Wikidoc\Search\LocalSearch;
use Base\Wikidoc\Search\ManualIndex;
use Base\Wikidoc\Search\ManualSearch;
use Base\Wikidoc\Search\TypesenseIndex;
use Base\Wikidoc\Site\Controller\ManualController as PublicManualController;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The manual is filesystem-backed: markdown files under the configured
 * documentation roots, no entities and no CRUD. The roots argument is
 * replaced by WikidocExtension from the processed `wikidoc.roots` config.
 */
return function (ContainerConfigurator $configurator) {

    $services = $configurator->services();
    $services->defaults()
        ->autowire(false)
        ->autoconfigure(false)
        ->public(false);

    $services->set(DocumentationRegistry::class)
        ->arg('$roots', []);

    $services->set(MarkdownRenderer::class);

    $services->set(SearchIndexBuilder::class)
        ->args([
            service(DocumentationRegistry::class),
            service(MarkdownRenderer::class),
            // Optional: without a pool the index is simply recomputed, which
            // is fine for a handful of files and is what happens in tests.
            service('cache.app')->nullOnInvalid(),
        ]);

    // Same manual container wiring base-bundle-admin's own controllers need:
    // autoconfigure(false) is set file-wide, so the #[Required] setContainer()
    // setter AbstractController relies on is never applied automatically, and
    // the controller 500s the moment it calls render()/createNotFoundException().
    // A service_locator (not a raw service_container reference) is the
    // supported way to reach the private services listed in
    // AbstractController::getSubscribedServices().
    $controllerServiceLocator = service_locator([
        'router' => service('router')->nullOnInvalid(),
        'request_stack' => service('request_stack')->nullOnInvalid(),
        'http_kernel' => service('http_kernel')->nullOnInvalid(),
        'serializer' => service('serializer')->nullOnInvalid(),
        'security.authorization_checker' => service('security.authorization_checker')->nullOnInvalid(),
        'twig' => service('twig')->nullOnInvalid(),
        'form.factory' => service('form.factory')->nullOnInvalid(),
        'security.token_storage' => service('security.token_storage')->nullOnInvalid(),
        'security.csrf.token_manager' => service('security.csrf.token_manager')->nullOnInvalid(),
        'parameter_bag' => service('parameter_bag')->nullOnInvalid(),
        'web_link.http_header_serializer' => service('web_link.http_header_serializer')->nullOnInvalid(),
    ]);

    // ---- The public manuals (docs/manuals.md) ------------------------------
    // One manual per package, read in its repository, in one or several
    // versions. The registry and the search are always there - a site may
    // use them from its own controllers; WikidocExtension fills their
    // arguments from `wikidoc.manuals`, `wikidoc.discover`..., registers the
    // Typesense index when `wikidoc.search.typesense.enabled` is set, and
    // removes the public controller unless `wikidoc.public.enabled` is.
    $services->set(ManualRegistry::class)
        ->args([[], [], [], service('cache.app')->nullOnInvalid()])
        ->public(true);

    $services->set(ManualMarkdownRenderer::class);

    $services->set(PageRenderer::class)
        ->args([service(ManualRegistry::class), service(ManualMarkdownRenderer::class), null]);

    $services->set(ManualIndex::class)
        ->args([service(ManualRegistry::class), service(MarkdownRenderer::class), service('cache.app')->nullOnInvalid(), 0]);

    $services->set(LocalSearch::class)
        ->args([service(ManualIndex::class)]);

    $services->set(ManualSearch::class)
        ->args([
            service(ManualRegistry::class),
            service(LocalSearch::class),
            service(TypesenseIndex::class)->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
        ])
        ->public(true);

    $services->set(BranchExporter::class)
        ->args([service(ManualRegistry::class)]);

    $services->set(SyncCommand::class)
        ->args([service(ManualRegistry::class), service(BranchExporter::class)])
        ->tag('console.command');

    $services->set(IndexCommand::class)
        ->args([service(ManualRegistry::class), service(ManualIndex::class), service(TypesenseIndex::class)->nullOnInvalid()])
        ->tag('console.command');

    $services->set(PublicManualController::class)
        ->args([
            service(ManualRegistry::class),
            service(PageRenderer::class),
            service(ManualMarkdownRenderer::class),
            service(ManualSearch::class),
            service('translator')->nullOnInvalid(),
        ])
        ->call('setContainer', [$controllerServiceLocator])
        ->public(true)
        ->tag('controller.service_arguments');

    if (class_exists('Base\\Admin\\Context\\AdminContext')) {
        $services->set(ManualController::class)
            ->args([
                service('Base\\Admin\\Context\\AdminContext'),
                service('Base\\Admin\\Menu\\MenuBuilder'),
                service(DocumentationRegistry::class),
                service(MarkdownRenderer::class),
                service(SearchIndexBuilder::class),
            ])
            ->call('setContainer', [$controllerServiceLocator])
            ->public(true)
            ->tag('controller.service_arguments');
    }
};
