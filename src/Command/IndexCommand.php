<?php

namespace Base\Wikidoc\Command;

use Base\Wikidoc\Manual\ManualRegistry;
use Base\Wikidoc\Search\ManualIndex;
use Base\Wikidoc\Search\TypesenseIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the manuals' search index: one Typesense collection per manual and
 * version (title, sections, text), rewritten from the files. Without the
 * engine - not installed, not switched on, or stopped - there is nothing to
 * fill: the search reads the pages' own index, and this says so.
 */
#[AsCommand(name: 'wikidoc:index', description: 'Writes the manuals\' search index into Typesense')]
class IndexCommand extends Command
{
    public function __construct(
        protected readonly ManualRegistry $manuals,
        protected readonly ManualIndex $index,
        protected readonly ?TypesenseIndex $typesense = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('manual', InputArgument::OPTIONAL, 'One manual, by its package name (default: all of them)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->manuals->reset();

        $manuals = $this->manuals->all();
        $only = $input->getArgument('manual');
        if (null !== $only) {
            $manual = $this->manuals->get((string) $only);
            if (null === $manual) {
                $io->error(sprintf('No manual "%s".', $only));

                return Command::FAILURE;
            }
            $manuals = [$manual->getKey() => $manual];
        }

        if (null === $this->typesense) {
            $records = 0;
            foreach ($manuals as $manual) {
                foreach ($manual->versions as $version) {
                    $this->index->forget($manual, $version);
                    $records += \count($this->index->records($manual, $version));
                }
            }
            $io->note(sprintf('Typesense is not switched on (wikidoc.search.typesense.enabled, glitchr/typesense-bundle): the search reads the local index - %d record(s) in %d manual(s).', $records, \count($manuals)));

            return Command::SUCCESS;
        }
        if (!$this->typesense->isAvailable()) {
            $io->warning('Typesense does not answer: nothing was indexed. The search falls back on the local index until it does.');

            return Command::FAILURE;
        }

        $rows = [];
        $keep = [];
        $total = 0;
        foreach ($manuals as $manual) {
            foreach ($manual->versions as $version) {
                $count = $this->typesense->index($manual, $version);
                $keep[] = $collection = $this->typesense->collection($manual, $version);
                $rows[] = [$manual->name, $version->name, $collection, $count];
                $total += $count;
            }
        }
        if (null === $only) {
            $this->typesense->prune($keep);
        }

        $io->table(['Manual', 'Version', 'Collection', 'Records'], $rows);
        $io->success(sprintf('%d record(s) in %d collection(s).', $total, \count($rows)));

        return Command::SUCCESS;
    }
}
