<?php

namespace Base\Wikidoc\Site\Controller;

use Base\Wikidoc\Documentation\DocPage;
use Base\Wikidoc\Manual\Manual;
use Base\Wikidoc\Manual\ManualMarkdownRenderer;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Manual\ManualVersion;
use Base\Wikidoc\Manual\PageRenderer;
use Base\Wikidoc\Search\ManualSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The manuals, on the public site, the way symfony.com/doc publishes its
 * own: a manual per package, a version per branch, each page with the
 * manual's contents, a breadcrumb, previous and next, its own headings,
 * and a link to the file it is edited in.
 *
 * Addresses, under `wikidoc.public.path` ("docs"):
 *
 *     /docs                                         every manual
 *     /docs/glitchr/omnitrade                       a manual, at its default version
 *     /docs/glitchr/omnitrade/1.x                   a version's home (the README, or docs/index.md)
 *     /docs/glitchr/omnitrade/1.x/webhooks          a page
 *     /docs/glitchr/omnitrade/current/webhooks      the same, whatever the default version is
 *     /docs/_search?q=webhook[&manual=...&version=...][&format=json]
 *
 * This controller lives outside src/Controller on purpose: the sites that
 * had the bundle before the public manuals import that whole folder for the
 * back office's manual, and must not gain these routes. A site that wants
 * them sets `wikidoc.public.enabled` and imports
 * `@WikidocBundle/src/Site/Controller`.
 */
class ManualController extends AbstractController
{
    public function __construct(
        protected readonly ManualRegistry $manuals,
        protected readonly PageRenderer $renderer,
        protected readonly ManualMarkdownRenderer $markdown,
        protected readonly ManualSearch $search,
        protected readonly ?TranslatorInterface $translator = null,
    ) {
    }

    #[Route('/%wikidoc.public.path%', name: 'wikidoc_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Wikidoc/client/index.html.twig', [
            'manuals' => $this->manuals->all(),
            'engine' => $this->search->engine(),
        ]);
    }

    /** Declared before the page route, whose path takes anything. */
    #[Route('/%wikidoc.public.path%/_search', name: 'wikidoc_search', methods: ['GET'], priority: 10)]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $manual = $this->manuals->get((string) $request->query->get('manual', ''));
        $version = $manual?->getVersion($request->query->get('version'));

        $result = $this->search->search($query, $manual?->getKey(), $version?->name, $request->query->getInt('limit', 30) ?: 30);
        foreach ($result->hits as $hit) {
            $hit->url = $this->generateUrl('wikidoc_manual', ['path' => $this->manuals->get($hit->manual)?->urlPath($hit->version, $hit->path) ?? $hit->manual])
                .(null !== $hit->anchor && '' !== $hit->anchor ? '#'.$hit->anchor : '');
        }

        if ('json' === $request->query->get('format') || 'json' === $request->getPreferredFormat()) {
            return new JsonResponse($result);
        }

        return $this->render('@Wikidoc/client/search.html.twig', [
            'query' => $query,
            'result' => $result,
            'manual' => $manual,
            'version' => $version,
            'terms' => \Base\Wikidoc\Search\LocalSearch::terms($query),
        ]);
    }

    /** A picture a page shows, served from the manual's repository: "glitchr/omnitrade/1.x/docs/img/flow.png". */
    #[Route('/%wikidoc.public.path%/_file/{path}', name: 'wikidoc_asset', requirements: ['path' => '.+'], methods: ['GET'], priority: 10)]
    public function asset(string $path): Response
    {
        $location = $this->manuals->resolve($path);
        $file = null !== $location ? PageRenderer::normalize($location->path) : null;
        if (null === $location || null === $file || !\in_array(strtolower(pathinfo($file, \PATHINFO_EXTENSION)), PageRenderer::PICTURES, true)) {
            throw $this->createNotFoundException();
        }

        $root = realpath($location->version->root);
        $real = realpath($location->version->root.'/'.$file);
        if (false === $root || false === $real || !str_starts_with($real, $root.'/') || !is_file($real)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($real);
        $response->setPublic();
        $response->setMaxAge(3600);
        // An SVG is a document: shown as a picture, never run as one.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/%wikidoc.public.path%/{path}', name: 'wikidoc_manual', requirements: ['path' => '.+'], methods: ['GET'])]
    public function page(string $path): Response
    {
        $location = $this->manuals->resolve($path);
        if (null === $location) {
            throw $this->createNotFoundException(sprintf('No manual at "%s".', $path));
        }
        $manual = $location->manual;
        $version = $location->version;

        // One address per page: the version is always spelled out.
        if (!$location->explicit) {
            return $this->redirectToRoute('wikidoc_manual', ['path' => $manual->urlPath($version->name, $location->path)]);
        }

        $pages = $this->manuals->pages($manual, $version);
        $page = '' === $location->path ? $pages->getDefault() : $pages->get($location->path);
        if (null === $page) {
            throw $this->createNotFoundException(sprintf('No page "%s" in %s %s.', $location->path, $manual->name, $version->name));
        }
        // A folder without an index: its first page.
        if ($page->isSection()) {
            $first = $this->firstPage($page);
            if (null === $first) {
                throw $this->createNotFoundException();
            }

            return $this->redirectToRoute('wikidoc_manual', ['path' => $manual->urlPath($version->name, $first->path)]);
        }

        $this->markdown->setLabels($this->calloutLabels());
        $rendered = $this->renderer->render(
            $manual,
            $version,
            $page,
            fn (string $target, ?string $anchor = null): string => $this->generateUrl('wikidoc_manual', ['path' => $manual->urlPath($version->name, $target)]).(null !== $anchor && '' !== $anchor ? '#'.$anchor : ''),
            fn (string $file): string => $this->generateUrl('wikidoc_asset', ['path' => $manual->urlPath($version->name, $file)]),
        );
        [$previous, $next] = $pages->getNeighbours($page);

        return $this->render('@Wikidoc/client/page.html.twig', [
            'manual' => $manual,
            'version' => $version,
            'versions' => $this->versions($manual, $page),
            'tree' => $pages->getTree(),
            'home' => $pages->get(''),
            'page' => $page,
            'rendered' => $rendered,
            'ancestors' => $pages->getAncestors($page),
            'previous' => $previous,
            'next' => $next,
        ]);
    }

    /**
     * The manual's versions, each with the address of this same page in it
     * (its home when that version has no such page).
     *
     * @return list<array{version: ManualVersion, path: string, same: bool}>
     */
    protected function versions(Manual $manual, DocPage $page): array
    {
        $versions = [];
        foreach ($manual->versions as $version) {
            $same = '' === $page->path || null !== $this->manuals->pages($manual, $version)->get($page->path);
            $versions[] = ['version' => $version, 'path' => $manual->urlPath($version->name, $same ? $page->path : ''), 'same' => $same];
        }

        return $versions;
    }

    protected function firstPage(DocPage $section): ?DocPage
    {
        foreach ($section->children as $child) {
            if (!$child->isSection()) {
                return $child;
            }
            if (null !== $found = $this->firstPage($child)) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected function calloutLabels(): array
    {
        $labels = [];
        foreach (ManualMarkdownRenderer::CALLOUTS as $type => $default) {
            $key = 'callout.'.$type;
            $label = $this->translator?->trans($key, [], 'wikidoc') ?? $default;
            $labels[$type] = $label === $key ? $default : $label;
        }

        return $labels;
    }
}
