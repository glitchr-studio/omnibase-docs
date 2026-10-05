---
title: The back office's manual
order: 10
---

# The back office's manual

With omnibase/admin installed, `/docs` shows the documentation inside the
back office: the page tree and the page's headings in a rail beside the
text, and a search palette (Ctrl K) that matches in the browser.

```yaml
# config/routes.yaml
wikidoc_controller:
    resource: "@WikidocBundle/src/Controller"
    type: attribute
    prefix: /
```

```yaml
# config/packages/wikidoc.yaml
wikidoc:
    # Documentation roots, LOWEST priority first.
    roots:
        base:
            path: '%kernel.project_dir%/vendor/glitchr/omnibase/docs'
            label: 'Base'
        app:
            path: '%kernel.project_dir%/docs'
            label: 'Application'
```

## Roots override each other by path

A page is identified by its path inside a root. When two roots hold
`install/requirements.md`, the later one wins and the manual shows a badge
on it: the rule Symfony uses for a bundle's templates. A root that does not
exist on disk is skipped - shipping the configuration before writing any
page is harmless.

The hierarchy is the folder tree. A folder is a section; its `index.md`, if
it has one, is the section's own page. The root's `index.md` is the manual's
home. Titles and order: see [Writing pages](writing.md).

## Routes

| Route | Address | |
|---|---|---|
| `backoffice_manual` | `/docs/{path}` | a page; without a path, the home or the first page |
| `backoffice_manual_search` | `/docs/_search` | the search records, as JSON, behind the same firewall |

Raw HTML in a page is escaped: these files are rendered on an authenticated
page, and markup that Markdown cannot write is refused rather than run.

## In code

```php
use Base\Wikidoc\Documentation\DocumentationRegistry;

$registry->getTree();                 // DocPage[]: the top-level pages and sections
$registry->get('install/requirements');
$registry->getDefault();              // the home page, else the first page
$registry->getSequence();             // every page in reading order
$registry->getNeighbours($page);      // [previous, next]
$registry->getAncestors($page);       // its breadcrumb
```
