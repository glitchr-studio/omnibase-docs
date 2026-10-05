<?php

namespace Base\Wikidoc\Tests\Search;

use Base\Wikidoc\Documentation\MarkdownRenderer;
use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Search\LocalSearch;
use Base\Wikidoc\Search\ManualIndex;
use Base\Wikidoc\Search\ManualSearch;
use Base\Wikidoc\Search\SearchResult;
use Base\Wikidoc\Search\TypesenseIndex;
use Base\Wikidoc\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class ManualSearchTest extends TestCase
{
    private ManualRegistry $registry;
    private ManualIndex $index;
    private FakeTypesense $typesense;

    protected function setUp(): void
    {
        $this->registry = Fixtures::registry();
        $this->index = new ManualIndex($this->registry, new MarkdownRenderer());
        $this->typesense = new FakeTypesense();
    }

    private function search(bool $withEngine = true): ManualSearch
    {
        return new ManualSearch($this->registry, new LocalSearch($this->index), $withEngine ? new TypesenseIndex($this->typesense, $this->index, 'docs') : null);
    }

    private function indexAll(): void
    {
        $engine = new TypesenseIndex($this->typesense, $this->index, 'docs');
        foreach ($this->registry->all() as $manual) {
            foreach ($manual->versions as $version) {
                $engine->index($manual, $version);
            }
        }
    }

    public function testWithoutAnEngineThePagesOwnIndexAnswers(): void
    {
        $result = $this->search(false)->search('webhook');

        self::assertSame(SearchResult::LOCAL, $result->engine);
        // Every manual, each in its default version: the widget's guide, its sections, and the plain README.
        self::assertSame(['acme/plain', 'acme/widget'], array_values(array_unique(array_map(static fn ($h) => $h->manual, $this->sorted($result)))));
        self::assertSame('20-guides/webhooks', $result->hits[0]->path, 'a title outranks the text');
        self::assertNull($result->hits[0]->section);
        self::assertSame(['current'], array_values(array_unique(array_map(static fn ($h) => $h->version, $result->hits))));
        self::assertStringContainsStringIgnoringCase('webhook', $result->hits[0]->snippet);
    }

    public function testASectionIsAResultOfItsOwn(): void
    {
        $result = $this->search(false)->search('signature');
        $hit = $result->hits[0];

        self::assertSame(['20-guides/webhooks', 'Webhooks', 'Signature', 'signature'], [$hit->path, $hit->title, $hit->section, $hit->anchor]);
    }

    public function testEveryWordMustBeThereAndCodeIsSearched(): void
    {
        self::assertSame(0, $this->search(false)->search('webhook zeppelin')->found);
        self::assertSame('installation', $this->search(false)->search('composer require')->hits[0]->path);
        self::assertSame(0, $this->search(false)->search('   ')->found);
    }

    public function testOneManualOneVersion(): void
    {
        $result = $this->search(false)->search('installing', 'acme/widget', '1.x');

        self::assertSame(1, $result->found);
        self::assertSame(['acme/widget', '1.x', 'installation'], [$result->hits[0]->manual, $result->hits[0]->version, $result->hits[0]->path]);
        self::assertSame(0, $this->search(false)->search('retries', 'acme/widget', '1.x')->found, '1.x has no webhooks page');
        self::assertSame(0, $this->search(false)->search('webhook', 'acme/unknown')->found);
    }

    public function testACollectionPerManualAndVersion(): void
    {
        $this->indexAll();

        self::assertSame(['docs_acme-plain_current', 'docs_acme-script_current', 'docs_acme-widget_1-x', 'docs_acme-widget_current'], $this->sortedKeys($this->typesense->schemas));
        self::assertSame(['manual', 'version', 'path', 'title', 'section', 'anchor', 'text', 'rank'], array_column($this->typesense->schemas['docs_acme-widget_current']['fields'], 'name'));

        // Title, sections, text: one document per page, one per heading.
        $documents = $this->typesense->documents['docs_acme-widget_current'];
        $page = array_values(array_filter($documents, static fn (array $d): bool => '20-guides/webhooks' === $d['path'] && !isset($d['section'])))[0];
        self::assertSame(['acme/widget', 'current', 'Webhooks', 0], [$page['manual'], $page['version'], $page['title'], $page['rank']]);
        self::assertStringContainsString('retried five times', $page['text']);
        $sections = array_values(array_filter($documents, static fn (array $d): bool => '20-guides/webhooks' === $d['path'] && isset($d['section'])));
        self::assertSame(['Signature', 'Retries'], array_column($sections, 'section'));
        self::assertSame(['signature', 'retries'], array_column($sections, 'anchor'));
        self::assertSame([1, 1], array_column($sections, 'rank'));
    }

    public function testIndexingAgainReplacesTheCollection(): void
    {
        $engine = new TypesenseIndex($this->typesense, $this->index, 'docs');
        $widget = $this->registry->get('acme/widget');

        $first = $engine->index($widget, $widget->getDefaultVersion());
        $second = $engine->index($widget, $widget->getDefaultVersion());

        self::assertSame($first, $second);
        self::assertCount($first, $this->typesense->documents['docs_acme-widget_current']);

        // A collection of a manual that is gone is dropped; another prefix's is not ours.
        $this->typesense->createCollection(['name' => 'docs_gone_1-x']);
        $this->typesense->createCollection(['name' => 'video']);
        self::assertSame(1, $engine->prune(['docs_acme-widget_current']));
        self::assertSame(['docs_acme-widget_current', 'video'], $this->sortedKeys($this->typesense->schemas));
    }

    public function testTheEngineAnswersWhenItIsThere(): void
    {
        $this->indexAll();
        $result = $this->search()->search('webhook');

        self::assertSame(SearchResult::TYPESENSE, $result->engine);
        self::assertSame(SearchResult::TYPESENSE, $this->search()->engine());
        self::assertSame('20-guides/webhooks', $result->hits[0]->path);
        self::assertSame('Webhooks', $result->hits[0]->title);
        self::assertStringNotContainsString('<mark>', $result->hits[0]->snippet, 'the page marks the words itself');
        self::assertContains('acme/plain', array_map(static fn ($h) => $h->manual, $result->hits));

        // One call, one search per manual at its default version.
        self::assertCount(1, $this->typesense->searches);
        self::assertSame(['docs_acme-plain_current', 'docs_acme-script_current', 'docs_acme-widget_current'], array_column($this->typesense->searches[0], 'collection'));
        self::assertSame('title,section,text', $this->typesense->searches[0][0]['query_by']);

        // One manual, one version: one collection.
        $this->search()->search('installing', 'acme/widget', '1.x');
        self::assertSame(['docs_acme-widget_1-x'], array_column($this->typesense->searches[1], 'collection'));
    }

    public function testTheLocalIndexAnswersWhenTheEngineIsDown(): void
    {
        $this->indexAll();
        $this->typesense->up = false;

        $result = $this->search()->search('webhook');

        self::assertSame(SearchResult::LOCAL, $result->engine);
        self::assertSame(SearchResult::LOCAL, $this->search()->engine());
        self::assertSame('20-guides/webhooks', $result->hits[0]->path);
        self::assertGreaterThan(1, $result->found);
    }

    public function testTheLocalIndexAnswersWhenAManualWasNeverIndexed(): void
    {
        // The engine is up, but wikidoc:index has not run: better the local answer than none.
        $result = $this->search()->search('webhook');

        self::assertSame(SearchResult::LOCAL, $result->engine);
        self::assertSame('20-guides/webhooks', $result->hits[0]->path);
    }

    public function testAResultSerialises(): void
    {
        $result = $this->search(false)->search('signature', 'acme/widget');
        foreach ($result->hits as $hit) {
            $hit->url = '/docs/'.$hit->manual.'/'.$hit->version.'/'.$hit->path.'#'.$hit->anchor;
        }
        $json = json_decode(json_encode($result), true);

        self::assertSame(['query', 'engine', 'found', 'hits'], array_keys($json));
        self::assertSame('local', $json['engine']);
        self::assertSame(['manual' => 'acme/widget', 'label' => 'acme/widget', 'version' => 'current', 'path' => '20-guides/webhooks', 'title' => 'Webhooks', 'section' => 'Signature', 'anchor' => 'signature'], \array_slice($json['hits'][0], 0, 7));
        self::assertSame('/docs/acme/widget/current/20-guides/webhooks#signature', $json['hits'][0]['url']);
    }

    /** @return list<\Base\Wikidoc\Search\SearchHit> */
    private function sorted(SearchResult $result): array
    {
        $hits = $result->hits;
        usort($hits, static fn ($a, $b): int => strcmp($a->manual, $b->manual));

        return $hits;
    }

    /** @return list<string> */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }
}
