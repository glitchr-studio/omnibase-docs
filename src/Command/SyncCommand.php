<?php

namespace Base\Wikidoc\Command;

use Base\Wikidoc\Manual\BranchExporter;
use Base\Wikidoc\Manual\Git;
use Base\Wikidoc\Manual\ManualRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The versions of the manuals: for each repository, the documentation of
 * the branches that are not checked out is copied under the export folder
 * (`wikidoc.export_dir`). The branch checked out needs no copy: it is read
 * where it is.
 */
#[AsCommand(name: 'wikidoc:sync', description: 'Copies the documentation of every version branch of the manuals\' repositories')]
class SyncCommand extends Command
{
    public function __construct(
        protected readonly ManualRegistry $manuals,
        protected readonly BranchExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('manual', InputArgument::OPTIONAL, 'One manual, by its package name (default: all of them)')
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, 'The remote whose branches are read beside the local ones', 'origin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->manuals->reset();

        $manuals = $this->manuals->all();
        if (null !== $only = $input->getArgument('manual')) {
            $manual = $this->manuals->get((string) $only);
            if (null === $manual) {
                $io->error(sprintf('No manual "%s".', $only));

                return Command::FAILURE;
            }
            $manuals = [$manual->getKey() => $manual];
        }
        if ([] === $manuals) {
            $io->note('No manual is configured (wikidoc.manuals, wikidoc.discover).');

            return Command::SUCCESS;
        }
        if (!BranchExporter::isAvailable()) {
            $io->warning('git is not installed here: each manual keeps the one version of its working tree.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failed = 0;
        foreach ($manuals as $manual) {
            $current = Git::branch($manual->path);
            $kept = [];
            $exported = [];
            foreach ($this->exporter->branches($manual, (string) $input->getOption('remote')) as $branch => $ref) {
                try {
                    if ($this->exporter->export($manual, $branch, $ref) > 0) {
                        $kept[] = $branch;
                        $exported[] = $branch;
                    }
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->warning(sprintf('%s, branch %s: %s', $manual->name, $branch, trim($e->getMessage())));
                }
            }
            $this->exporter->prune($manual, $kept);
            $rows[] = [$manual->name, $current ?? '(no branch)', [] === $exported ? '-' : implode(', ', $exported)];
        }
        $this->manuals->reset();

        $io->table(['Manual', 'Read in place', 'Exported'], $rows);
        $io->success(sprintf('%d manual(s) synchronised.', \count($manuals)));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
