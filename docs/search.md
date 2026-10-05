---
title: Search
order: 40
---

# Search

Every page of the public manuals has a search field; Ctrl K (or `/`) opens
it as a palette that asks the server as one types, in the manual being read
first, in every manual on demand. Without JavaScript the field is a form
that goes to the results page.

```
/docs/_search?q=webhook                                   every manual, each in its default version
/docs/_search?q=webhook&manual=glitchr/omnitrade          one manual, its default version
/docs/_search?q=webhook&manual=glitchr/omnibus&version=1.x
/docs/_search?q=webhook&format=json                       what the palette reads
```

A result is a page, or a section of a page (it links to the heading). The
answer says who gave it: `engine` is `typesense` or `local`.

## The local index

With nothing configured, the search reads the pages' own index: one record
per page and one per heading - title, section, text (code included: a
class name or a command is what people search for) - built by
`SearchIndexBuilder`, the index of the back office's palette, and matched
in PHP. Every word of the query must be found; a title outranks a heading,
a heading the text. No typo tolerance.

## Typesense

```sh
composer require glitchr/typesense-bundle
```

```yaml
# config/packages/typesense.yaml: the connection (no entity mapping is needed)
typesense:
    default_connection: default
    connections:
        default:
            secret: '%env(TYPESENSE_SECRET)%'
            scheme: '%env(TYPESENSE_SCHEME)%'
            host: '%env(resolve:TYPESENSE_HOST)%'
            port: '%env(int:TYPESENSE_PORT)%'
            options:
                connection_timeout_seconds: 1

# config/packages/wikidoc.yaml
wikidoc:
    search:
        typesense:
            enabled: true
            connection: ~        # typesense-bundle's connection; null: its default one
            prefix: wikidoc      # the collections are <prefix>_<manual>_<version>
```

```sh
bin/console wikidoc:index                      # every manual, every version
bin/console wikidoc:index glitchr/omnitrade    # one
```

One collection per manual and version (`wikidoc_glitchr-omnitrade_1-x`),
dropped and written again at each run, with the same records as the local
index:

| Field | |
|---|---|
| `manual`, `version` | facets |
| `path`, `anchor` | where the result links to (not indexed) |
| `title`, `section`, `text` | searched, weighted 4, 3, 1, prefixes and two typos allowed |
| `rank` | 0 for a page, 1 for a section: the page first, for the same match |

Run without a manual's name, the command also drops the collections of
manuals and versions that are no longer published. Run it after
`wikidoc:sync`, after a deployment, or from cron.

A search over every manual is one `multi_search` call, one search per
manual (40 per call).

## When the engine does not answer

The local index answers, and the result says `local`:

- glitchr/typesense-bundle is not installed, or `enabled` is false;
- the server is down or times out (keep `connection_timeout_seconds` short);
- a manual that is searched has no collection yet (`wikidoc:index` has not
  run since it appeared).

Nothing is shown to the visitor but the results; a notice goes to the log.
`wikidoc:index` itself exits with an error when the engine is switched on
and does not answer, and says so when it is switched off.

## In code

```php
use Base\Wikidoc\Search\ManualSearch;

$result = $search->search('webhook');                                // every manual
$result = $search->search('webhook', 'glitchr/omnitrade', '1.x', limit: 10);

$result->engine;     // "typesense" or "local"
$result->found;
foreach ($result->hits as $hit) {
    $hit->manual; $hit->version; $hit->path;      // "glitchr/omnitrade", "1.x", "webhooks"
    $hit->title; $hit->section; $hit->anchor;     // "Webhooks", "Signature", "signature"
    $hit->snippet;
}
```

`Base\Wikidoc\Search\TypesenseClientInterface` is the handful of calls made
to the engine; a test replaces it with its own (see
`tests/Search/FakeTypesense.php`).
