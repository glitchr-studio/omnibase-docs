<?php

namespace Base\Wikidoc\Manual;

use Symfony\Component\Process\Process;

/**
 * Copies the documentation of a branch that is not checked out - its docs/
 * folder and its README - out of the repository, under the export folder,
 * where ManualRegistry finds it as a version of the manual.
 *
 * The repository is only read (`git ls-tree`, `git show`): nothing is
 * checked out, fetched or written in it, so a read-only mount is enough.
 * Needs the `git` binary; without it the manual keeps the one version of
 * its working tree.
 */
class BranchExporter
{
    /** What is copied beside the Markdown: the pictures a page shows. */
    protected const EXTENSIONS = ['md', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif'];

    public function __construct(protected readonly ManualRegistry $manuals)
    {
    }

    public static function isAvailable(): bool
    {
        if (!class_exists(Process::class)) {
            return false;
        }
        try {
            return (new Process(['git', '--version']))->run() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The branches of the manual's repository that are versions and are not
     * the one checked out: name => ref.
     *
     * @return array<string, string>
     */
    public function branches(Manual $manual, string $remote = 'origin'): array
    {
        $current = Git::branch($manual->path);

        return array_filter(
            Git::branches($manual->path, $remote),
            fn (string $ref, string $name): bool => $name !== $current && $this->manuals->isVersionBranch($name),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Writes the branch's documentation under the export folder.
     *
     * @return int the number of files copied; 0 when the branch has no documentation (nothing is then left on disk)
     */
    public function export(Manual $manual, string $version, string $ref): int
    {
        $git = Git::dir($manual->path);
        if (null === $git) {
            throw new \RuntimeException(sprintf('"%s" is not a git repository.', $manual->path));
        }

        $version0 = $manual->getDefaultVersion();
        $paths = array_values(array_filter([$manual->docs, $version0?->readme]));

        $listing = $this->git($git, ['ls-tree', '-r', '--name-only', '-z', $ref, '--', ...$paths]);
        $files = array_values(array_filter(explode("\0", $listing), static fn (string $file): bool => '' !== $file
            && \in_array(strtolower(pathinfo($file, \PATHINFO_EXTENSION)), self::EXTENSIONS, true)));

        $target = $this->manuals->exportDir($manual, $version);
        self::remove($target);
        if ([] === $files) {
            return 0;
        }

        foreach ($files as $file) {
            $destination = $target.'/'.$file;
            if (!is_dir(\dirname($destination)) && !@mkdir(\dirname($destination), 0775, true) && !is_dir(\dirname($destination))) {
                throw new \RuntimeException(sprintf('Cannot write under "%s".', $target));
            }
            file_put_contents($destination, $this->git($git, ['show', $ref.':'.$file]));
        }

        file_put_contents($target.'/.wikidoc.json', json_encode([
            'manual' => $manual->name,
            'version' => $version,
            'branch' => $version,
            'ref' => $ref,
            'commit' => trim($this->git($git, ['rev-parse', $ref])),
            'exported' => date(\DATE_ATOM),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return \count($files);
    }

    /** Removes the exports of branches that are gone (or became the checked-out one). */
    public function prune(Manual $manual, array $keep): int
    {
        $removed = 0;
        foreach (glob($this->manuals->exportDir($manual).'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = json_decode((string) @file_get_contents($dir.'/.wikidoc.json'), true) ?: [];
            if (!\in_array((string) ($meta['version'] ?? basename($dir)), $keep, true)) {
                self::remove($dir);
                ++$removed;
            }
        }

        return $removed;
    }

    /** @param list<string> $arguments */
    protected function git(string $gitDir, array $arguments): string
    {
        // safe.directory: the repository is often someone else's on this
        // machine (a mount, another user) - it is only read.
        $process = new Process(['git', '-c', 'safe.directory=*', '--git-dir='.$gitDir, ...$arguments]);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }

    protected static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
