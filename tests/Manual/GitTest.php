<?php

namespace Base\Wikidoc\Tests\Manual;

use Base\Wikidoc\Manual\BranchExporter;
use Base\Wikidoc\Manual\Git;
use Base\Wikidoc\Manual\ManualRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/wikidoc-git-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp]))->run();
    }

    public function testABranchItsSiblingsAndItsOriginAreReadFromTheGitFolder(): void
    {
        // A hand-made .git: no binary is needed to read it.
        $git = $this->tmp.'/repo/.git';
        mkdir($git.'/refs/heads', 0777, true);
        mkdir($git.'/refs/remotes/origin', 0777, true);
        file_put_contents($git.'/HEAD', "ref: refs/heads/2.x\n");
        file_put_contents($git.'/refs/heads/2.x', str_repeat('a', 40)."\n");
        file_put_contents($git.'/refs/remotes/origin/3.x', str_repeat('b', 40)."\n");
        file_put_contents($git.'/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n".str_repeat('c', 40)." refs/remotes/origin/1.x\n".str_repeat('d', 40)." refs/remotes/origin/2.x\n".str_repeat('e', 40)." refs/remotes/gitlab/0.x\n".str_repeat('f', 40)." refs/tags/v1.0\n");
        file_put_contents($git.'/config', "[core]\n\tbare = false\n[remote \"gitlab\"]\n\turl = git@gitlab.example.org:acme/widget.git\n[remote \"origin\"]\n\turl = git@github.com:acme/widget.git\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n[user]\n\tname = Someone\n");

        self::assertSame('2.x', Git::branch($this->tmp.'/repo'));
        self::assertSame(['2.x' => 'refs/heads/2.x', '1.x' => 'refs/remotes/origin/1.x', '3.x' => 'refs/remotes/origin/3.x'], Git::branches($this->tmp.'/repo'));
        self::assertSame('https://github.com/acme/widget', Git::origin($this->tmp.'/repo'));
        self::assertSame('https://gitlab.example.org/acme/widget', Git::origin($this->tmp.'/repo', 'gitlab'));
    }

    public function testOutsideARepository(): void
    {
        self::assertNull(Git::branch($this->tmp));
        self::assertSame([], Git::branches($this->tmp));
        self::assertNull(Git::origin($this->tmp));
    }

    public function testAnAddressOfAnyKindBecomesAWebAddress(): void
    {
        self::assertSame('https://github.com/acme/widget', Git::webUrl('git@github.com:acme/widget.git'));
        self::assertSame('https://github.com/acme/widget', Git::webUrl('https://github.com/acme/widget.git'));
        self::assertSame('https://gitlab.example.org/group/sub/widget', Git::webUrl('ssh://git@gitlab.example.org/group/sub/widget.git'));
        self::assertSame('https://github.com/acme/widget', Git::webUrl('https://token@github.com/acme/widget'));
        self::assertNull(Git::webUrl('/srv/git/widget.git'));
    }

    public function testTheOtherBranchesAreCopiedOutOfTheRepositoryAndBecomeVersions(): void
    {
        if (!BranchExporter::isAvailable()) {
            self::markTestSkipped('git is not installed here.');
        }

        $repo = $this->tmp.'/widget';
        mkdir($repo.'/docs/img', 0777, true);
        $git = function (string ...$arguments) use ($repo): void {
            (new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.org', '-c', 'init.defaultBranch=1.x', ...$arguments], $repo))->mustRun();
        };
        $git('init', '-q');
        $git('checkout', '-q', '-b', '1.x');
        file_put_contents($repo.'/composer.json', '{"name": "acme/widget"}');
        file_put_contents($repo.'/README.md', "# Widget 1\n");
        file_put_contents($repo.'/docs/installation.md', "# Installing 1\n");
        file_put_contents($repo.'/docs/img/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($repo.'/docs/notes.txt', 'not documentation');
        $git('add', '-A');
        $git('commit', '-q', '-m', 'one');
        $git('checkout', '-q', '-b', '2.x');
        file_put_contents($repo.'/README.md', "# Widget 2\n");
        file_put_contents($repo.'/docs/upgrade.md', "# Upgrading\n");
        $git('add', '-A');
        $git('commit', '-q', '-m', 'two');
        $git('checkout', '-q', '-b', 'feature/search');
        $git('checkout', '-q', '2.x');

        $registry = new ManualRegistry(['acme/widget' => ['path' => $repo]], [], ['export_dir' => $this->tmp.'/export']);
        $exporter = new BranchExporter($registry);
        $widget = $registry->get('acme/widget');

        self::assertSame(['2.x'], array_keys($widget->versions), 'before the export: the branch checked out, alone');
        self::assertSame(['1.x' => 'refs/heads/1.x'], $exporter->branches($widget), 'a feature branch is not a version');

        self::assertSame(3, $exporter->export($widget, '1.x', 'refs/heads/1.x'));
        $registry->reset();
        $widget = $registry->get('acme/widget');

        self::assertSame(['2.x', '1.x'], array_keys($widget->versions));
        self::assertSame('2.x', $widget->getDefaultVersion()->name);
        $old = $registry->pages($widget, $widget->getVersion('1.x'));
        self::assertSame('Widget 1', $old->get('')->title);
        self::assertSame('Installing 1', $old->get('installation')->title);
        self::assertNull($old->get('upgrade'));
        self::assertFileExists($registry->exportDir($widget, '1.x').'/docs/img/a.svg');
        self::assertFileDoesNotExist($registry->exportDir($widget, '1.x').'/docs/notes.txt');
        self::assertSame('Upgrading', $registry->pages($widget)->get('upgrade')->title);

        // A branch that is gone leaves no export behind.
        self::assertSame(1, $exporter->prune($widget, []));
        $registry->reset();
        self::assertSame(['2.x'], array_keys($registry->get('acme/widget')->versions));
    }
}
