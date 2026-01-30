<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
use Tum\Installer\Domain\Strategy\SetupStrategyInterface;
use Tum\Installer\Service\Worker\DatabaseImporterService;
use Tum\Installer\Service\Worker\FolderStructureService;
use Tum\Installer\Service\Worker\SiteConfigurationService;
use Tum\Installer\Service\Worker\YamlLoaderService;
use TYPO3\CMS\Core\Database\ConnectionPool;

class InstallerService
{
    /**
     * @param iterable<SetupStrategyInterface> $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
        private readonly YamlLoaderService $yamlLoader,
        private readonly DatabaseImporterService $dbImporter,
        private readonly SiteConfigurationService $siteConfigService,
        private readonly FolderStructureService $folderService,
        private readonly ConnectionPool $connectionPool
    ) {}

    public function isSetupAllowed(SetupType $type): bool
    {
        if ($type !== SetupType::SETUP1 && $type !== SetupType::STANDALONE) {
            return true;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $existingPages = $queryBuilder
            ->select('uid', 'title', 'is_siteroot', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', 0),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($existingPages as $page) {
            $title = $page['title'] ?? '';
            $isSiteRoot = (bool)($page['is_siteroot'] ?? false);

            if ($title === 'sRoot') {
                return false;
            }

            if ($isSiteRoot && !str_starts_with($title, 'Archiv')) {
                return false;
            }
        }

        return true;
    }

    public function install(InstallationConfig $initialConfig, ?callable $progressCallback = null): void
    {
        // Step 0: Validation
        $progressCallback?.(0, 'validation');

        if (!$this->isSetupAllowed($initialConfig->type)) {
            throw new \RuntimeException(
                sprintf('Installation blockiert: Typ "%s" kann nicht zusätzlich installiert werden.', $initialConfig->type->value)
            );
        }

        // 1. Strategie wählen
        $strategy = null;
        foreach ($this->strategies as $s) {
            if ($s->supports($initialConfig->type)) {
                $strategy = $s;
                break;
            }
        }

        if (!$strategy) {
            throw new \RuntimeException("Keine Strategie gefunden für Setup Typ: " . $initialConfig->type->value);
        }

        // 2. Vorbereiten
        $config = $strategy->prepare($initialConfig);

        // Step 1: Ordner erstellen
        $progressCallback?.(1, 'folders');
        $this->folderService->createStructure($config);

        // Step 2: Seiten anlegen (YAML laden)
        $progressCallback?.(2, 'pages');
        $yamlPath = $strategy->getYamlFilePath($config);
        $yamlData = $this->yamlLoader->loadAndMerge($yamlPath);

        // Step 3: BE-Groups erstellen
        $progressCallback?.(3, 'begroups');

        // Step 4: BE-Users erstellen
        $progressCallback?.(4, 'beusers');

        // Import all data (pages, groups, users, etc.)
        $this->dbImporter->import($yamlData, $config);

        // Step 5: Site Config
        $progressCallback?.(5, 'siteconfig');
        $this->siteConfigService->generate($config);

        // Step 6: TypoScript / Nacharbeiten
        $progressCallback?.(6, 'typoscript');
        $strategy->postProcess($config);
    }
}