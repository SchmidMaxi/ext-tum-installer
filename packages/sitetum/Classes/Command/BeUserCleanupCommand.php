<?php

declare(strict_types=1);

namespace ElementareTeilchen\Sitetum\Command;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A command to clean up inactive backend users.
 *
 * This command provides functionality to:
 * 1. Deactivate active users whose last login (or creation date if never logged in)
 * is older than a configurable number of days.
 * 2. Soft-delete (set deleted=1) users who have been disabled for a configurable
 * number of days.
 *
 * It respects a whitelist of 'maintainer' usernames loaded from YAML files to
 * prevent critical accounts from being touched. Changes are applied via the
 * TYPO3 DataHandler to ensure all hooks and logging mechanisms are triggered.
 */
#[AsCommand(
    name: 'sitetum:beuser:cleanup-inactive',
    description: 'Deactivate BE users after N days and soft-delete disabled users after M days, honoring a maintainer whitelist.'
)]
final class BeUserCleanupCommand extends Command
{
    private const TABLE = 'be_users';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'deactivate-after-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Deactivate if last login is older than N days. For never-logged-in accounts, creation date (crdate) is used.',
                90
            )
            ->addOption(
                'delete-after-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Soft-delete users that have been disabled for at least M days (based on modification timestamp `tstamp`).',
                360
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'If set, no changes are written to the database (preview only).'
            )
            ->addOption(
                'include-admins',
                null,
                InputOption::VALUE_NEGATABLE,
                'Process admin accounts (admin=1) as well. Defaults to false.',
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deactivateAfterDays = max(30, (int)$input->getOption('deactivate-after-days'));
        $deleteAfterDays = max(30, (int)$input->getOption('delete-after-days'));
        $dryRun = (bool)$input->getOption('dry-run');
        $includeAdmins = (bool)$input->getOption('include-admins');

        $now = new DateTimeImmutable('now');
        $thresholdDeactivate = $now->sub(new DateInterval('P' . $deactivateAfterDays . 'D'))->getTimestamp();
        $thresholdDeleteByDisable = $now->sub(new DateInterval('P' . $deleteAfterDays . 'D'))->getTimestamp();

        $maintainers = $this->loadMaintainerUsernames();

        $output->writeln(sprintf(
            '<info>Start</info> %s | deactivate > %d days | delete (disabled since) > %d days | dry-run=%s | include-admins=%s | maintainers=%d',
            $now->format(DateTimeImmutable::ATOM),
            $deactivateAfterDays,
            $deleteAfterDays,
            $dryRun ? 'yes' : 'no',
            $includeAdmins ? 'yes' : 'no',
            count($maintainers)
        ));

        // Step 1: Find candidates for deactivation.
        $toDeactivateRaw = $this->fetchDeactivateCandidates($thresholdDeactivate, $includeAdmins);
        [$toDeactivate, $deactivateProtected] = $this->filterProtectedUsers($toDeactivateRaw, $maintainers);
        $output->writeln(sprintf('Found %d user(s) to deactivate, %d protected by maintainer list.', count($toDeactivate), count($deactivateProtected)));

        // Step 2: Find candidates for soft-deletion.
        $toSoftDeleteRaw = $this->fetchDeleteCandidates($thresholdDeleteByDisable, $includeAdmins);
        [$toSoftDelete, $softDeleteProtected] = $this->filterProtectedUsers($toSoftDeleteRaw, $maintainers);
        $output->writeln(sprintf('Found %d user(s) to soft-delete, %d protected by maintainer list.', count($toSoftDelete), count($softDeleteProtected)));

        if ($dryRun) {
            $this->dumpPreview($output, 'Deactivate', $toDeactivate);
            if ($deactivateProtected) {
                $this->dumpPreview($output, 'Deactivate (protected - skipped)', $deactivateProtected, true);
            }
            $this->dumpPreview($output, 'Soft-delete', $toSoftDelete);
            if ($softDeleteProtected) {
                $this->dumpPreview($output, 'Soft-delete (protected - skipped)', $softDeleteProtected, true);
            }
            $output->writeln('<comment>Dry run finished. No changes were made.</comment>');
            return Command::SUCCESS;
        }

        // Apply changes using TYPO3's DataHandler.
        $deactivatedCount = $this->applyDeactivationWithDataHandler($toDeactivate, $output);
        $softDeletedCount = $this->applySoftDeleteWithDataHandler($toSoftDelete, $output);

        $output->writeln(sprintf('<info>Deactivated:</info> %d', $deactivatedCount));
        if ($deactivateProtected) {
            $output->writeln(sprintf('<comment>Skipped deactivation (protected):</comment> %d', count($deactivateProtected)));
        }
        $output->writeln(sprintf('<info>Soft-deleted (deleted=1):</info> %d', $softDeletedCount));
        if ($softDeleteProtected) {
            $output->writeln(sprintf('<comment>Skipped deletion (protected):</comment> %d', count($softDeleteProtected)));
        }
        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }

    // --- Helper Methods ---

    /**
     * Loads maintainer usernames from `config/system/maintainer.yaml`.
     */
    private function loadMaintainerUsernames(): array
    {
        $file = Environment::getProjectPath() . '/config/system/maintainer.yaml';
        $usernames = [];

        if (is_file($file) && is_readable($file)) {
            try {
                $yaml = Yaml::parseFile($file);
                if (is_array($yaml) && isset($yaml['maintainers']) && is_array($yaml['maintainers'])) {
                    foreach ($yaml['maintainers'] as $entry) {
                        if (is_string($entry) && $entry !== '') {
                            $usernames[] = $entry;
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore YAML file if invalid
            }
        }

        $usernames[] = '_cli_'; // The CLI user should never be touched.

        return array_values(array_unique(array_map(static fn($v) => mb_strtolower((string)$v), $usernames)));
    }

    /**
     * Splits a list of user rows into two arrays: one to process and one that is protected.
     */
    private function filterProtectedUsers(array $rows, array $maintainers): array
    {
        if (empty($rows) || empty($maintainers)) {
            return [$rows, []];
        }
        $process = [];
        $protected = [];

        foreach ($rows as $row) {
            $username = mb_strtolower((string)$row['username']);
            if (in_array($username, $maintainers)) {
                $protected[] = $row;
            } else {
                $process[] = $row;
            }
        }
        return [$process, $protected];
    }

    /**
     * Fetches users who are candidates for deactivation.
     */
    private function fetchDeactivateCandidates(int $threshold, bool $includeAdmins): array
    {
        /** @var QueryBuilder $qb */
        $qb = $this->getQueryBuilder();
        // We only need to find users regardless of their start/end time.
        // Default restrictions (deleted=0, disable=0) are desired here.
        $qb->getRestrictions()
            ->removeByType(StartTimeRestriction::class)
            ->removeByType(EndTimeRestriction::class);

        $qb->select('uid AS id', 'username', 'email', 'lastlogin', 'crdate', 'tstamp', 'admin')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->or(
                    $qb->expr()->and(
                        $qb->expr()->gt('lastlogin', 0),
                        $qb->expr()->lte('lastlogin', ':threshold')
                    ),
                    $qb->expr()->and(
                        $qb->expr()->eq('lastlogin', 0),
                        $qb->expr()->lte('crdate', ':threshold')
                    )
                )
            )
            ->setParameter('threshold', $threshold, ParameterType::INTEGER)
            ->orderBy('uid', 'ASC');

        if (!$includeAdmins) {
            $qb->andWhere($qb->expr()->eq('admin', 0));
        }

        return $this->normalizeUserRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Fetches disabled users who are candidates for soft-deletion.
     */
    private function fetchDeleteCandidates(int $threshold, bool $includeAdmins): array
    {
        /** @var QueryBuilder $qb */
        $qb = $this->getQueryBuilder();
        // We MUST remove default restrictions to find records with `disable=1`.
        $qb->getRestrictions()->removeAll();

        $qb->select('uid AS id', 'username', 'email', 'lastlogin', 'crdate', 'tstamp', 'admin')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('deleted', 0))
            ->andWhere($qb->expr()->eq('disable', 1))
            // Use raw string for this complex condition for robustness
            ->andWhere('((tstamp > 0 AND tstamp <= :threshold) OR (tstamp = 0 AND (CASE WHEN lastlogin = 0 THEN crdate ELSE lastlogin END) <= :threshold))')
            ->setParameter('threshold', $threshold, ParameterType::INTEGER)
            ->orderBy('uid', 'ASC');

        if (!$includeAdmins) {
            $qb->andWhere($qb->expr()->eq('admin', 0));
        }

        return $this->normalizeUserRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Normalizes database rows to a consistent integer/string format.
     */
    private function normalizeUserRows(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'username' => (string)($row['username'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'lastlogin' => (int)$row['lastlogin'],
            'crdate' => (int)$row['crdate'],
            'tstamp' => (int)$row['tstamp'],
            'admin' => (int)$row['admin'],
        ], $rows);
    }

    /**
     * Deactivates users via the DataHandler.
     */
    private function applyDeactivationWithDataHandler(array $rows, OutputInterface $output): int
    {
        if (empty($rows)) {
            return 0;
        }

        if ($output->isVerbose()) {
            $output->writeln('<comment>Deactivating users (verbose):</comment>');
            foreach ($rows as $row) {
                $this->dumpPreviewRow($output, $row);
            }
        }

        $dataHandler = $this->makeDataHandler();
        $dataMap = ['be_users' => []];
        foreach ($rows as $row) {
            $dataMap['be_users'][(int)$row['id']] = ['disable' => 1];
        }

        $dataHandler->start($dataMap, []);

        // TODO v13: This method is deprecated in v12 and removed in v13.
        // Replace with: $dataHandler->processDataMap();
        // (or $dataHandler->process() if cmdmap is also processed)
        $dataHandler->process_datamap();

        return count($rows);
    }

    /**
     * Soft-deletes users via the DataHandler.
     */
    private function applySoftDeleteWithDataHandler(array $rows, OutputInterface $output): int
    {
        if (empty($rows)) {
            return 0;
        }

        if ($output->isVerbose()) {
            $output->writeln('<comment>Soft-deleting users (verbose):</comment>');
            foreach ($rows as $row) {
                $this->dumpPreviewRow($output, $row);
            }
        }

        $dataHandler = $this->makeDataHandler();

        $cmdMap = [];
        foreach ($rows as $row) {
            $cmdMap[self::TABLE][(int)$row['id']]['delete'] = 1;
        }

        $dataHandler->start([], $cmdMap);

        // TODO v13: This method is deprecated in v12 and removed in v13.
        // Replace with: $dataHandler->processCommandMap();
        // (or $dataHandler->process() if cmdmap is also processed)
        $dataHandler->process_cmdmap();

        return count($rows);
    }

    /**
     * Prepares a DataHandler instance with the correct admin user context.
     */
    private function makeDataHandler(): DataHandler
    {
        // Initialize backend user authentication to ensure the new backend user can be created with proper permissions
        Bootstrap::initializeBackendAuthentication();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // avoid step-up authentication error: Manual call in Scheduler would throw exception because sudo-mode
        // for changing BE users is missing
        $dataHandler->bypassAccessCheckForRecords = true;
        return $dataHandler;
    }

    /**
     * Dumps a preview of users to be processed.
     */
    private function dumpPreview(OutputInterface $output, string $title, array $rows, bool $isProtected = false): void
    {
        if (empty($rows)) {
            $output->writeln("<comment>$title: (none)</comment>");
            return;
        }
        $output->writeln("<comment>$title preview:</comment>");
        foreach ($rows as $row) {
            $this->dumpPreviewRow($output, $row, $isProtected);
        }
    }

    /**
     * Dumps a single user row for preview.
     */
    private function dumpPreviewRow(OutputInterface $output, array $row, bool $isProtected = false): void
    {
        $output->writeln(sprintf(
            '  uid=%-4d | user=%-25s | lastlogin=%-10s | crdate=%-10s | tstamp=%-16s | admin=%d%s',
            $row['id'],
            (string)$row['username'],
            $row['lastlogin'] > 0 ? date('Y-m-d', $row['lastlogin']) : 'never',
            date('Y-m-d', $row['crdate']),
            $row['tstamp'] > 0 ? date('Y-m-d H:i', $row['tstamp']) : '0',
            $row['admin'],
            $isProtected ? ' (protected)' : ''
        ));
    }

    /**
     * Gets a QueryBuilder for the be_users table.
     */
    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE);
    }
}
