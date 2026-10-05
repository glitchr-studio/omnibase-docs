<?php

namespace Base\Wikidoc\Manual;

/**
 * One manual: the documentation of one package, read in its repository
 * (its `docs/` folder and its README), in one or several versions.
 *
 * Its name is the package's own - "glitchr/omnitrade", "omnitrade/stripe",
 * "@glitchr/stickyjs" - and its key is that name as an address spells it:
 * lower case, without the "@" an npm scope starts with.
 */
final class Manual
{
    /** @param array<string, ManualVersion> $versions keyed by name, newest first */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $path,
        public readonly array $versions,
        public readonly ?string $defaultVersion = null,
        public readonly ?string $repository = null,
        public readonly ?string $description = null,
        public readonly string $docs = 'docs',
    ) {
    }

    public static function keyOf(string $name): string
    {
        return strtolower(trim(ltrim(trim($name), '@'), '/'));
    }

    /** The manual's part of an address: "glitchr/omnitrade". */
    public function getKey(): string
    {
        return self::keyOf($this->name);
    }

    public function getVersion(?string $name = null): ?ManualVersion
    {
        if (null === $name || '' === $name || 'current' === $name) {
            return $this->getDefaultVersion();
        }

        return $this->versions[$name] ?? null;
    }

    /** The version a reader lands on: the configured one, else the checkout's branch, else the newest. */
    public function getDefaultVersion(): ?ManualVersion
    {
        if (null !== $this->defaultVersion && isset($this->versions[$this->defaultVersion])) {
            return $this->versions[$this->defaultVersion];
        }

        foreach ($this->versions as $version) {
            if ($version->live) {
                return $version;
            }
        }

        return [] === $this->versions ? null : $this->versions[array_key_first($this->versions)];
    }

    /** "glitchr/omnitrade/1.x/webhooks": what follows the manuals' prefix in an address. */
    public function urlPath(?string $version = null, string $page = ''): string
    {
        $version = $this->getVersion($version);

        return implode('/', array_filter([$this->getKey(), $version?->name, trim($page, '/')], static fn (?string $part): bool => null !== $part && '' !== $part));
    }
}
