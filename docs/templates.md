---
title: Templates and look
order: 50
---

# Templates and look

The public pages extend the host's `layout1.html.twig` and fill its `title`,
`description`, `stylesheets` and `content` blocks, as the other omnibase
bundles do.

| Template | |
|---|---|
| `@Wikidoc/client/index.html.twig` | every manual |
| `@Wikidoc/client/page.html.twig` | a page |
| `@Wikidoc/client/search.html.twig` | the results |
| `@Wikidoc/client/_tree.html.twig` | the manual's contents |
| `@Wikidoc/client/_search.html.twig` | the search field |

A site overrides one by placing a file of the same name under
`templates/bundles/WikidocBundle/client/` - typically `index.html.twig`,
to list the manuals the way its own catalogue groups them.

## The look is the site's

`public/css/manual.css` (`bundles/wikidoc/css/manual.css` once
`assets:install` ran) has no colour and no typeface of its own. A site sets
custom properties, in its light and its dark mode:

```css
:root {
    --manual-ink: #101418;        /* text */
    --manual-soft: #566070;       /* secondary text */
    --manual-line: #d5dae1;       /* rules, borders */
    --manual-bg: #ffffff;         /* the page */
    --manual-surface: #f2f4f5;    /* code, hovered rows */
    --manual-accent: #1238d6;     /* links, the current page */
    --manual-font: "Recursive", sans-serif;
    --manual-font-display: "Archivo", sans-serif;
    --manual-font-mono: "Recursive", monospace;
    --manual-top: 5rem;           /* the height of a sticky header: where the side columns stick */
}
```

Also: `--manual-on-accent`, `--manual-mark` (the searched words),
`--manual-radius`, `--manual-code-bg`, `--manual-code-ink`, the code's
colours `--manual-hl-keyword`, `-type`, `-property`, `-value`, `-number`,
`-comment`, `-variable`, and the callouts' `--manual-note`, `-tip`,
`-warning`, `-caution`.

## The script

`public/js/manual.js` has no dependency: the search palette, the "copy"
buttons, the current heading in "On this page", the contents folded behind
a button on a narrow screen. The pages load it themselves; a site that
bundles its scripts may import it instead - loading it twice does nothing -
and it sets itself up again after a page is swapped in by transparentjs
(`transparent:load`).

Without the script everything is still reachable: the search field posts to
the results page, the version menu is a `<details>`, the contents are in
the page.

## Texts

`translations/wikidoc+intl-icu.{en,fr}.yaml`: `@wikidoc.manual.*`,
`@wikidoc.search.*`, `@wikidoc.callout.*`.
