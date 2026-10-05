---
title: omnibase/docs
---

# omnibase/docs

The documentation of a site, read from Markdown files. A page is a file:
it is written, reviewed and versioned with the code it describes, and no
editor, no table and no migration stands between the file and the reader.

| | Where | For whom |
|---|---|---|
| [The back office's manual](back-office.md) | `/docs`, inside omnibase/admin | the people who run the site |
| [Public manuals](manuals.md) | `/docs/<package>/<version>/<page>` | everyone: one manual per package, one version per branch |
| [Search](search.md) | a palette on every page, `/docs/_search` | Typesense, or the pages' own index |

Then: [how a page is written](writing.md) and [how the pages look](templates.md).

## What is on by default

Only the back office's manual. The public manuals (`wikidoc.public.enabled`),
their discovery (`wikidoc.manuals`, `wikidoc.discover`) and the engine
(`wikidoc.search.typesense.enabled`) are off until a site sets them, and
their controller lives outside the folder the back office's routes are
imported from: a site that had the bundle before them gains no route, no
service it uses, no change in what its manual renders.

## Requirements

PHP 8.1, glitchr/omnibase, league/commonmark. Optional:

| Package | Adds |
|---|---|
| omnibase/admin | the back office's manual |
| glitchr/typesense-bundle | the search in Typesense |
| tempest/highlight (PHP 8.4) | code blocks coloured on the server |
| symfony/process, and the `git` binary | `wikidoc:sync`: the versions that are not checked out |
