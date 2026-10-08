# omnibase/docs

The documentation of a site, read from Markdown files: nothing is kept in a
database, a page is a file, versioned with the code it describes.

- **A manual in the back office** (`/docs`, with omnibase/admin): the
  bundle's and the application's pages, the application overriding the
  bundle's by path, a search palette.
- **Public manuals**, the way symfony.com/doc publishes its own: one manual
  per package, read in the `docs/` folder and the README of its repository,
  one version per branch; contents, breadcrumb, previous and next, the
  page's own headings, anchors, code blocks, callouts, relative links that
  follow, "Edit this page" to the file on its forge.
- **A search** over the public manuals: Typesense (a collection per manual
  and version) through glitchr/typesense-bundle, or the pages' own index
  when the engine is off or does not answer.

The public manuals and the engine are off until a site asks for them: a site
that only has the back office's manual changes nothing.

```sh
composer require omnibase/docs
```

```php
// config/bundles.php
Base\Wikidoc\WikidocBundle::class => ['all' => true],
```

The bundle's namespace and its configuration key are still `Wikidoc`
(`Base\Wikidoc\`, `wikidoc:`), from the package it was before.

## Documentation

- [The back office's manual](docs/back-office.md)
- [Public manuals](docs/manuals.md): configuration, versions, addresses
- [Writing pages](docs/writing.md): titles, order, links, callouts, code
- [Search](docs/search.md): Typesense, the local index, the commands
- [Templates and look](docs/templates.md)

## Tests

```sh
# in glitchr/omnibase's harness (core/docker)
docker compose -f compose.yml -f compose.checkouts.yml run --rm omnibase test docs
# or from an application that has the bundle
php vendor/bin/phpunit -c vendor/omnibase/docs/phpunit.xml.dist
```

## Licence

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
