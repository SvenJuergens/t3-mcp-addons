<?php

declare(strict_types=1);

namespace SvenJuergens\T3McpAddons\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Repeatable variant of the core command "workspace:autopublish".
 *
 * The core command clears publish_time after a run, so a workspace publishes
 * exactly once. This command leaves publish_time untouched: as long as the date
 * is in the past, every run publishes whatever the workspace holds at that
 * moment. publish_time therefore acts as an on/off switch - set a past date to
 * enable auto publishing, clear it to stop.
 *
 * Meant for setups where content is written via MCP into a workspace and has
 * to reach the live site without a manual publish step, typically while a site
 * is being built. Run it from the scheduler or a cron job. Where content should
 * go through a review before it is published, do not schedule it.
 */
#[AsCommand(
    'mcp-addons:workspace:publish',
    'Publish workspaces with a publication date in the past, repeatedly (publish_time is kept).'
)]
final class WorkspacePublishCommand extends Command
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Publishes every workspace whose publish_time is set and lies in the past.' . LF
            . 'Unlike workspace:autopublish, publish_time is not reset, so the command' . LF
            . 'can run on a schedule and publishes the current workspace content each time.'
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Only list what would be published, without touching any record.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Make sure the _cli_ user is loaded
        Bootstrap::initializeBackendAuthentication();
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');

        $workspaces = $this->getAffectedWorkspacesToPublish();
        if ($workspaces === []) {
            $io->note('No workspace has a publication date in the past.');
            return Command::SUCCESS;
        }

        $hasErrors = false;
        $publishedRecords = 0;
        foreach ($workspaces as $workspace) {
            $workspaceId = (int)$workspace['uid'];
            $cmd = $this->workspaceService->getCmdArrayForPublishWS($workspaceId);
            $recordCount = $this->countRecords($cmd);

            if ($recordCount === 0) {
                $io->writeln(sprintf('Workspace %d: nothing to publish.', $workspaceId));
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('Workspace %d: would publish %d records.', $workspaceId, $recordCount));
                foreach ($cmd as $table => $records) {
                    $io->writeln(sprintf('  %s: %s', $table, implode(', ', array_keys($records))));
                }
                continue;
            }

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmd);
            $dataHandler->process_cmdmap();

            if ($dataHandler->errorLog !== []) {
                $hasErrors = true;
                $io->warning(sprintf('Workspace %d reported errors while publishing:', $workspaceId));
                $io->listing($dataHandler->errorLog);
                continue;
            }

            $publishedRecords += $recordCount;
            $io->writeln(sprintf('Workspace %d: published %d records.', $workspaceId, $recordCount));
        }

        if ($hasErrors) {
            $io->error('Publishing finished with errors.');
            return Command::FAILURE;
        }
        if ($dryRun) {
            $io->success('Dry run finished, nothing was published.');
        } elseif ($publishedRecords > 0) {
            $io->success(sprintf('Published %d records.', $publishedRecords));
        } else {
            $io->note('Nothing to do.');
        }
        return Command::SUCCESS;
    }

    /**
     * Fetch all sys_workspace records with a publication date in the past.
     *
     * @return array<int, array{uid: int, publish_time: int}>
     */
    private function getAffectedWorkspacesToPublish(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('uid', 'publish_time')
            ->from('sys_workspace')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->neq(
                    'publish_time',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->lte(
                    'publish_time',
                    $queryBuilder->createNamedParameter($GLOBALS['EXEC_TIME'], Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string, array<int, mixed>> $cmd
     */
    private function countRecords(array $cmd): int
    {
        $count = 0;
        foreach ($cmd as $records) {
            $count += count($records);
        }
        return $count;
    }
}
