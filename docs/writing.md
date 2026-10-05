---
title: Writing pages
order: 30
---

# Writing pages

A page is a Markdown file (CommonMark with GitHub's extensions: tables,
strikethrough, task lists, autolinks). It should read well on its forge
too: nothing here needs a syntax GitHub does not render.

## Title and order

```markdown
---
title: Installation
order: 10
---

# Installing the widget
```

The title in the contents is the front matter's `title`, else the first
`# heading`, else the file's name. The order is the front matter's `order`,
else a number the file's name starts with (`10-getting-started.md`,
`20-architecture/`), else 500; pages of the same order are sorted by title.
The front matter is not printed.

A page without a first-level heading gets its title printed above it.

## Links

Write links for the repository; the manual follows them:

| Written in `docs/installation.md` | Goes to |
|---|---|
| `[webhooks](webhooks.md)`, `[guides](guides/)` | that page, in the same manual and version |
| `[signature](webhooks.md#signature)` | that page's anchor |
| `[overview](../README.md)` | the manual's home |
| `[the bridge](../src/Bridge/Symfony)` | the folder on the forge, at the version's branch |
| `![flow](img/flow.png)` | the picture, served by the site |
| `[anchor](#configuration)`, `https://...` | unchanged |

A link is relative to the file it is written in, wherever that file is: a
README links to `docs/installation.md`. A link that climbs out of the
repository is left as written. Pictures served: png, jpg, gif, svg, webp,
avif.

## Callouts

Written as GitHub writes them - a quote whose first line is a marker:

```markdown
> [!NOTE]
> The widget needs PHP 8.2.

> [!WARNING]
> Never commit the signing secret.
```

`[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]`, `[!CAUTION]`. Their
titles are translated (`wikidoc.callout.*`: "Astuce", "Attention"...). The
back office's manual renders them as plain quotes.

## Code

````markdown
```php
$widget = new Widget(secret: 'not-a-real-secret');
```
````

A fenced block is framed, named after its language, and has a "copy"
button. With tempest/highlight installed it is coloured on the server -
php, yaml, twig, sh / bash, js, json, html, css, scss, sql, xml, ini,
dotenv, nginx, dockerfile, diff, and what else the library knows; a
language it does not know is shown plain. Without the library every block
is plain.

Raw HTML is escaped, in every page.

## Headings

Second to fourth level headings get an anchor (`## Signature` is
`#signature`), linked from "On this page" (second and third level) and from
the search results.
