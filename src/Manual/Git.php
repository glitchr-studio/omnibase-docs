<?php

namespace Base\Wikidoc\Manual;

/**
 * What a manual needs to know of a repository, read in its `.git` folder:
 * the branch checked out, the branches there are, where it is published.
 * Files only - no `git` binary, so it works on a read-only mount and in an
 * image that ships without git. Copying another branch's files is
 * BranchExporter's job, and that one does need git.
 */
final class Git
{
    /** The `.git` folder of a checkout (a worktree's `.git` is a file naming it), or null. */
    public static function dir(string $path): ?string
    {
        $git = rtrim($path, '/').'/.git';
        if (is_dir($git)) {
            return $git;
        }
        if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) @file_get_contents($git), $m)) {
            $dir = trim($m[1]);
            $dir = str_starts_with($dir, '/') ? $dir : rtrim($path, '/').'/'.$dir;

            return is_dir($dir) ? $dir : null;
        }

        return null;
    }

    /** The branch checked out; null outside a repository or on a detached HEAD. */
    public static function branch(string $path): ?string
    {
        if (null === $git = self::dir($path)) {
            return null;
        }

        return preg_match('#^ref:\s*refs/heads/(.+)$#m', (string) @file_get_contents($git.'/HEAD'), $m) ? trim($m[1]) : null;
    }

    /**
     * Every branch, local ones first then the remote's: name => ref
     * ("1.x" => "refs/heads/1.x", "2.x" => "refs/remotes/origin/2.x").
     *
     * @return array<string, string>
     */
    public static function branches(string $path, string $remote = 'origin'): array
    {
        if (null === $git = self::dir($path)) {
            return [];
        }
        // A worktree keeps its branches in the main repository.
        if (is_file($git.'/commondir')) {
            $common = trim((string) @file_get_contents($git.'/commondir'));
            $git = str_starts_with($common, '/') ? $common : $git.'/'.$common;
        }

        $refs = [];
        foreach (['refs/heads/', 'refs/remotes/'.$remote.'/'] as $prefix) {
            $base = $git.'/'.$prefix;
            if (is_dir($base)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    $refs[$prefix.substr(str_replace('\\', '/', $file->getPathname()), \strlen($base))] = true;
                }
            }
        }
        foreach (preg_split('/\R/', (string) @file_get_contents($git.'/packed-refs')) ?: [] as $line) {
            if (preg_match('#^[0-9a-f]{40,64} (refs/(?:heads|remotes/'.preg_quote($remote, '#').')/\S+)$#', $line, $m)) {
                $refs[$m[1]] = true;
            }
        }

        ksort($refs);
        $branches = [];
        foreach (['refs/heads/', 'refs/remotes/'.$remote.'/'] as $prefix) {
            foreach (array_keys($refs) as $ref) {
                $name = str_starts_with($ref, $prefix) ? substr($ref, \strlen($prefix)) : null;
                if (null !== $name && 'HEAD' !== $name && !isset($branches[$name])) {
                    $branches[$name] = $ref;
                }
            }
        }

        return $branches;
    }

    /** Where the repository is published, as a web address: "https://github.com/glitchr-studio/omnitrade". */
    public static function origin(string $path, string $remote = 'origin'): ?string
    {
        if (null === $git = self::dir($path)) {
            return null;
        }
        if (is_file($git.'/commondir')) {
            $common = trim((string) @file_get_contents($git.'/commondir'));
            $git = str_starts_with($common, '/') ? $common : $git.'/'.$common;
        }
        $config = (string) @file_get_contents($git.'/config');
        if (!preg_match('/^\[remote "'.preg_quote($remote, '/').'"\]\s*$(.*?)(?=^\[|\z)/ms', $config, $section)
            || !preg_match('/^\s*url\s*=\s*(\S+)/m', $section[1], $m)) {
            return null;
        }

        return self::webUrl($m[1]);
    }

    /** "git@github.com:org/repo.git", "ssh://git@host/org/repo.git", "https://host/org/repo.git" → "https://host/org/repo". */
    public static function webUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('#^(?:ssh://)?git@([^:/]+)[:/](.+?)(?:\.git)?/?$#', $url, $m)) {
            return 'https://'.$m[1].'/'.$m[2];
        }
        if (preg_match('#^https?://(?:[^@/]+@)?([^/]+)/(.+?)(?:\.git)?/?$#', $url, $m)) {
            return 'https://'.$m[1].'/'.$m[2];
        }

        return null;
    }
}
