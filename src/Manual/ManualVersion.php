<?php

namespace Base\Wikidoc\Manual;

/**
 * One version of a manual: a branch of the package's repository.
 *
 * `live` is the branch checked out in the working tree - its files are read
 * where they are, an edit shows at the next request. The other branches are
 * copies written by `wikidoc:sync` (git archive) under the export folder.
 */
final class ManualVersion
{
    public function __construct(
        public readonly string $name,
        public readonly string $branch,
        /** The folder holding this version's `docs/` and README: the repository, or its export. */
        public readonly string $root,
        public readonly string $docs = 'docs',
        public readonly ?string $readme = 'README.md',
        public readonly bool $live = true,
    ) {
    }

    public function getDocsDir(): string
    {
        return rtrim($this->root, '/').'/'.trim($this->docs, '/');
    }

    public function getReadmeFile(): ?string
    {
        if (null === $this->readme || '' === $this->readme) {
            return null;
        }
        $file = rtrim($this->root, '/').'/'.ltrim($this->readme, '/');

        return is_file($file) ? $file : null;
    }

    /** Whether there is anything to read: a README, or at least one Markdown file under the docs folder. */
    public function hasPages(): bool
    {
        if (null !== $this->getReadmeFile()) {
            return true;
        }
        if (!is_dir($dir = $this->getDocsDir())) {
            return false;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('md' === strtolower($file->getExtension())) {
                return true;
            }
        }

        return false;
    }

    /** A file's path inside the repository ("docs/webhooks.md"), or null when it is not this version's. */
    public function relative(string $file): ?string
    {
        $root = rtrim((string) (realpath($this->root) ?: $this->root), '/').'/';
        $file = (string) (realpath($file) ?: $file);

        return str_starts_with($file, $root) ? substr($file, \strlen($root)) : null;
    }
}
