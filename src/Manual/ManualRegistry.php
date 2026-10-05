<?php

namespace Base\Wikidoc\Manual;

use Base\Wikidoc\Documentation\DocumentationRegistry;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The manuals a site publishes: the ones its configuration names
 * (`wikidoc.manuals`) and the ones found on disk (`wikidoc.discover`: every
 * folder a pattern matches that holds a composer.json or a package.json).
 *
 * A manual's versions are the branches of its repository: the one checked
 * out, read where it is, and those `wikidoc:sync` copied under the export
 * folder. Nothing is kept in a database - the registry is rebuilt from the
 * files, and the list of discovered folders alone is cached for a while.
 */
class ManualRegistry
{
    /** @var array<string, Manual>|null keyed by Manual::getKey() */
    protected ?array $manuals = null;

    /** @var array<string, DocumentationRegistry> */
    protected array $pages = [];

    /**
     * @param array<string, array<string, mixed>> $manuals  `wikidoc.manuals`, keyed by name
     * @param list<string>                        $discover glob patterns
     * @param array{export_dir?: string, branches?: string, docs?: string, readme?: string|false|null, remote?: string, cache_ttl?: int, exclude?: list<string>} $options
     */
    public function __construct(
        protected readonly array $configured = [],
        protected readonly array $discover = [],
        protected readonly array $options = [],
        protected readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /** @return array<string, Manual> keyed by key ("glitchr/omnitrade"), sorted */
    public function all(): array
    {
        if (null !== $this->manuals) {
            return $this->manuals;
        }

        $manuals = [];
        foreach ($this->discovered() as $definition) {
            if (null !== $manual = $this->build($definition)) {
                $manuals[$manual->getKey()] = $manual;
            }
        }
        // A manual the configuration names wins over one found on disk.
        foreach ($this->configured as $name => $definition) {
            $definition['name'] = (string) ($definition['name'] ?? $name);
            if (null !== $manual = $this->build($definition)) {
                $manuals[$manual->getKey()] = $manual;
            }
        }
        ksort($manuals);

        return $this->manuals = $manuals;
    }

    public function get(string $name): ?Manual
    {
        return $this->all()[Manual::keyOf($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    /**
     * What an address names. "glitchr/omnitrade/1.x/webhooks" is the page
     * "webhooks" of version 1.x; without a version, or with "current", the
     * manual's default version. The longest manual key wins, so
     * "omnibase/docs" is never read as a page of a manual called "omnibase".
     */
    public function resolve(string $path): ?ManualLocation
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => '' !== $s));
        $manuals = $this->all();

        for ($length = min(\count($segments), 3); $length >= 1; --$length) {
            $key = Manual::keyOf(implode('/', \array_slice($segments, 0, $length)));
            if (!isset($manuals[$key])) {
                continue;
            }
            $manual = $manuals[$key];
            $rest = \array_slice($segments, $length);

            $explicit = false;
            $version = $manual->getDefaultVersion();
            if ([] !== $rest && ('current' === $rest[0] || isset($manual->versions[$rest[0]]))) {
                $explicit = 'current' !== $rest[0];
                $version = $manual->getVersion(array_shift($rest));
            }
            if (null === $version) {
                return null;
            }

            return new ManualLocation($manual, $version, implode('/', $rest), $explicit);
        }

        return null;
    }

    /** The pages of one version of a manual: its docs folder, its README as the home page. */
    public function pages(Manual $manual, ?ManualVersion $version = null): DocumentationRegistry
    {
        $version ??= $manual->getDefaultVersion();
        if (null === $version) {
            return new DocumentationRegistry([]);
        }

        return $this->pages[$manual->getKey().'@'.$version->name] ??= new DocumentationRegistry([
            'docs' => ['path' => $version->getDocsDir(), 'label' => $manual->label, 'home' => $version->getReadmeFile()],
        ]);
    }

    /** Where `wikidoc:sync` writes the branch `$version` of a manual. */
    public function exportDir(Manual|string $manual, string $version = ''): string
    {
        $key = $manual instanceof Manual ? $manual->getKey() : Manual::keyOf($manual);

        return rtrim((string) ($this->options['export_dir'] ?? sys_get_temp_dir().'/wikidoc'), '/')
            .'/'.str_replace('/', '--', $key).('' !== $version ? '/'.self::safe($version) : '');
    }

    /** Whether a branch is a version ("1.x", "2.0", "main"): `wikidoc.branches`. */
    public function isVersionBranch(string $branch): bool
    {
        return 1 === preg_match((string) ($this->options['branches'] ?? '/^(\d+\.(x|\d+)|main|master)$/'), $branch);
    }

    /** Forget what was read: after `wikidoc:sync`, or in a long-running process. */
    public function reset(): void
    {
        $this->manuals = null;
        $this->pages = [];
        $this->cache?->deleteItem($this->cacheKey());
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function build(array $definition): ?Manual
    {
        $path = rtrim((string) ($definition['path'] ?? ''), '/');
        if ('' === $path || !is_dir($path)) {
            return null; // not mounted, not cloned yet: fewer manuals, never an error
        }

        $package = self::readPackage($path);
        $name = (string) ($definition['name'] ?? $package['name'] ?? basename($path));
        $docs = trim((string) ($definition['docs'] ?? $this->options['docs'] ?? 'docs'), '/');
        $readme = $definition['readme'] ?? $this->options['readme'] ?? 'README.md';
        $readme = false === $readme || null === $readme ? null : (string) $readme;
        $remote = (string) ($this->options['remote'] ?? 'origin');

        $versions = [];
        foreach ((array) ($definition['versions'] ?? []) as $versionName => $given) {
            // Named versions, each with a checkout of its own: {path: ..., branch: ...}.
            $given = \is_array($given) ? $given : ['path' => $given];
            $root = rtrim((string) ($given['path'] ?? $path), '/');
            if (is_dir($root)) {
                $versions[(string) $versionName] = new ManualVersion((string) $versionName, (string) ($given['branch'] ?? $versionName), $root, $docs, $readme, $root === $path || (bool) ($given['live'] ?? true));
            }
        }

        if ([] === $versions) {
            $branch = Git::branch($path);
            $live = $branch ?? (string) ($definition['version'] ?? 'current');
            $versions[$live] = new ManualVersion($live, $branch ?? $live, $path, $docs, $readme, true);

            // The other branches, where wikidoc:sync left them.
            $key = Manual::keyOf($name);
            foreach (glob($this->exportDir($key).'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
                $meta = json_decode((string) @file_get_contents($dir.'/.wikidoc.json'), true) ?: [];
                $versionName = (string) ($meta['version'] ?? basename($dir));
                if (!isset($versions[$versionName])) {
                    $versions[$versionName] = new ManualVersion($versionName, (string) ($meta['branch'] ?? $versionName), $dir, $docs, $readme, false);
                }
            }
        }

        $versions = array_filter($versions, static fn (ManualVersion $v): bool => $v->hasPages());
        if ([] === $versions) {
            return null; // nothing to read
        }
        uksort($versions, [self::class, 'compareVersions']);

        $repository = $definition['repository'] ?? null;
        $repository = \is_string($repository) && '' !== $repository
            ? rtrim($repository, '/')
            : (Git::origin($path, $remote) ?? self::repositoryOf($package));

        return new Manual(
            name: $name,
            label: (string) ($definition['label'] ?? $name),
            path: $path,
            versions: $versions,
            defaultVersion: isset($definition['default_version']) ? (string) $definition['default_version'] : null,
            repository: $repository,
            description: isset($definition['description']) ? (string) $definition['description'] : ($package['description'] ?? null),
            docs: $docs,
        );
    }

    /**
     * The folders the `discover` patterns match that hold a package.
     *
     * @return list<array{path: string}>
     */
    protected function discovered(): array
    {
        if ([] === $this->discover) {
            return [];
        }

        $ttl = (int) ($this->options['cache_ttl'] ?? 0);
        $item = $ttl > 0 ? $this->cache?->getItem($this->cacheKey()) : null;
        if ($item?->isHit()) {
            return $item->get();
        }

        $found = [];
        $exclude = (array) ($this->options['exclude'] ?? []);
        foreach ($this->discover as $pattern) {
            foreach (glob(rtrim((string) $pattern, '/'), \GLOB_ONLYDIR | \GLOB_BRACE) ?: [] as $dir) {
                if (!is_file($dir.'/composer.json') && !is_file($dir.'/package.json')) {
                    continue;
                }
                $name = self::readPackage($dir)['name'] ?? basename($dir);
                foreach ($exclude as $skip) {
                    if (fnmatch((string) $skip, $name) || fnmatch((string) $skip, $dir)) {
                        continue 2;
                    }
                }
                $found[$dir] = ['path' => $dir];
            }
        }
        $found = array_values($found);

        if (null !== $item) {
            $item->set($found);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        }

        return $found;
    }

    /** @return array{name?: string, description?: string, homepage?: string, support?: array<string, string>, repository?: mixed} */
    protected static function readPackage(string $dir): array
    {
        foreach (['composer.json', 'package.json'] as $file) {
            if (is_file($dir.'/'.$file)) {
                $json = json_decode((string) @file_get_contents($dir.'/'.$file), true);
                if (\is_array($json) && isset($json['name'])) {
                    return $json;
                }
            }
        }

        return [];
    }

    /** @param array<string, mixed> $package */
    protected static function repositoryOf(array $package): ?string
    {
        $candidates = [
            $package['support']['source'] ?? null,
            \is_array($package['repository'] ?? null) ? ($package['repository']['url'] ?? null) : ($package['repository'] ?? null),
        ];
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && null !== $url = Git::webUrl(preg_replace('#^git\+#', '', $candidate) ?? $candidate)) {
                return $url;
            }
        }

        return null;
    }

    /** Newest first: "main" and "master" ahead, then 3.x, 2.x, 1.x. */
    public static function compareVersions(string $a, string $b): int
    {
        $rank = static fn (string $v): int => \in_array($v, ['main', 'master', 'current'], true) ? 1 : 0;
        if ($rank($a) !== $rank($b)) {
            return $rank($b) <=> $rank($a);
        }

        return version_compare(str_replace('x', '9999', $b), str_replace('x', '9999', $a)) ?: strcmp($a, $b);
    }

    protected function cacheKey(): string
    {
        return 'wikidoc.manuals.'.md5(serialize($this->discover).serialize($this->options['exclude'] ?? []));
    }

    protected static function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? $name;
    }
}
