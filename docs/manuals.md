---
title: Public manuals
order: 20
---

# Public manuals

One manual per package, read where the package is written: the `docs/`
folder and the README of its repository. One version per branch. The pages
are rendered on the public site, the way symfony.com/doc renders its own.

## Switching them on

```yaml
# config/packages/wikidoc.yaml
wikidoc:
    public:
        enabled: true
        path: docs            # the address they are published under
    manuals:
        glitchr/omnitrade:
            path: '%env(OMNI_REPOS)%/omnitrade/core'    # the repository's checkout
    # or: every package found under these folders
    discover:
        - '%env(OMNI_REPOS)%/*/*'
```

```yaml
# config/routes.yaml
wikidoc_manuals:
    resource: "@WikidocBundle/src/Site/Controller"
    type: attribute
    prefix: /
```

The public controller is in `src/Site/Controller`, outside `src/Controller`:
a site that imports the latter for the back office's manual does not get
the public routes with it.

## What a manual is made of

| In the repository | In the manual |
|---|---|
| `README.md` | the home page - unless `docs/index.md` exists, which is then the home, the README staying readable as the first page |
| `docs/*.md` | a page each |
| `docs/<folder>/` | a section; `docs/<folder>/index.md` is its own page |
| `composer.json` or `package.json` | the manual's name (`glitchr/omnitrade`, `@glitchr/stickyjs`) and description |
| `.git/config`, remote `origin` | the repository's web address, for "Edit this page" |

A package without a `docs/` folder and with a README is a manual of one
page; a package with neither is not a manual.

### Naming them, or finding them

`wikidoc.manuals` names manuals one by one, keyed by the package's name:

| Key | Default | |
|---|---|---|
| `path` | (required) | the repository's checkout |
| `label` | the name | what the pages call it |
| `description` | the package's | |
| `docs` | `wikidoc.docs` (`docs`) | the Markdown folder inside the repository |
| `readme` | `wikidoc.readme` (`README.md`) | `false`: no README |
| `repository` | the `origin` remote, else the package's `support.source` / `repository` | its web address |
| `default_version` | the branch checked out | the version an address without one opens |
| `versions` | (see below) | versions with a checkout of their own |

`wikidoc.discover` takes glob patterns: every folder matched that holds a
`composer.json` or a `package.json` and something to read is a manual named
after its package. `wikidoc.discover_exclude` leaves packages out, by name
or by folder (`fnmatch` patterns). A manual named in `wikidoc.manuals` wins
over one discovered under the same name. A folder that is not there - not
mounted, not cloned yet - is skipped.

The folders are looked at when a page is asked for, not when the container
is compiled: a repository cloned later is simply there. `wikidoc.cache_ttl`
(seconds, 0 by default) keeps the list of discovered folders, and the local
search records, for a while.

## Versions

A version is a branch of the repository whose name matches
`wikidoc.branches` (by default `1.x`, `2.0`, `main`, `master`...).

- **The branch checked out** is read where it is: edit a file, reload the
  page. It is the manual's default version.
- **The other branches** are copied by a command, with `git` (`ls-tree`,
  `show`: the repository is only read, a read-only mount is enough):

```sh
bin/console wikidoc:sync                    # every manual
bin/console wikidoc:sync glitchr/omnibus    # one
bin/console wikidoc:sync --remote=github    # read another remote's branches beside the local ones
```

It writes each branch's `docs/` and README (Markdown and pictures) under
`wikidoc.export_dir` (`var/wikidoc/<package>/<branch>/`), where the next
request finds it as a version, and removes the copy of a branch that is
gone. Local branches come first, then the remote's (`origin`): a branch that
was fetched but never checked out is a version too. Without `git` or
symfony/process the command says so, and each manual keeps the one version
of its working tree.

Run it after a deployment or a fetch, or from cron.

Versions with a checkout of their own, when a site prefers worktrees to
copies:

```yaml
wikidoc:
    manuals:
        glitchr/omnibus:
            path: '/srv/repos/omnibus/core'
            versions:
                '2.x': '/srv/repos/omnibus/core'
                '1.x': { path: '/srv/worktrees/omnibus-1.x', branch: '1.x' }
```

Versions are listed newest first (`main` and `master` ahead, then `3.x`,
`2.x`, `1.x`). A folder that is not a git repository has one version, named
`current`.

## Addresses

| Address | |
|---|---|
| `/docs` | every manual (`wikidoc_index`) |
| `/docs/glitchr/omnitrade` | redirects to the default version |
| `/docs/glitchr/omnitrade/1.x` | the version's home |
| `/docs/glitchr/omnitrade/1.x/webhooks` | a page (`wikidoc_manual`, `path: glitchr/omnitrade/1.x/webhooks`) |
| `/docs/glitchr/omnitrade/current/webhooks` | redirects to the page in the default version |
| `/docs/_search?q=webhook` | the search (`wikidoc_search`): see [Search](search.md) |
| `/docs/_file/glitchr/omnitrade/1.x/docs/img/flow.png` | a picture of the manual (`wikidoc_asset`) |

A manual's part of an address is its package name, lower case, without the
`@` of an npm scope: `glitchr/stickyjs` for `@glitchr/stickyjs`. One
address per page: an address without a version redirects to the one that
spells it out. A folder without an `index.md` redirects to its first page.

```twig
<a href="{{ path('wikidoc_manual', {path: manual.urlPath()}) }}">{{ manual.label }}</a>
<a href="{{ path('wikidoc_manual', {path: manual.urlPath('1.x', 'webhooks')}) }}">Webhooks, 1.x</a>
```

## What a page shows

- the manual's contents, the current branch open;
- a breadcrumb: Documentation / the manual / the version / the sections / the page;
- the version menu, each entry going to the same page in that version (to
  its home when that version has no such page), and a notice on a page of a
  version that is not the default one;
- the page, its headings carrying anchors;
- "On this page": the page's second and third level headings, the one being
  read marked;
- "Edit this page", to the file on its forge, and the path of the source file;
- previous and next, in reading order.

"Edit this page" is `{repository}/edit/{branch}/{file}` (GitLab:
`{repository}/-/edit/...`); `wikidoc.edit_url` sets another pattern with the
same three placeholders. A manual whose repository is not known has no such
link. The source stays the Markdown in the repository: there is no editor
here.

## In code

```php
use Base\Wikidoc\Manual\ManualRegistry;

$manuals->all();                               // array<string, Manual>, keyed "glitchr/omnitrade"
$manual = $manuals->get('glitchr/omnitrade');  // or null
$manual->versions;                             // array<string, ManualVersion>, newest first
$manual->getDefaultVersion();
$manual->urlPath('1.x', 'webhooks');           // "glitchr/omnitrade/1.x/webhooks"
$manuals->pages($manual, $version);            // a DocumentationRegistry: tree, pages, reading order
$manuals->resolve('glitchr/omnitrade/1.x/webhooks');   // ManualLocation: manual, version, path
```

`ManualRegistry` and `ManualSearch` are registered whether the public
controller is or not: a site may read manuals from controllers of its own.
