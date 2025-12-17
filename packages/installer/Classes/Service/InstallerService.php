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

    public function install(InstallationConfig $initialConfig): void
    {
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

        // 3. Ordner
        $this->folderService->createStructure($config);

        // 4. Import
        $yamlPath = $strategy->getYamlFilePath($config);
        $yamlData = $this->yamlLoader->loadAndMerge($yamlPath);
        $this->dbImporter->import($yamlData, $config);

        // 5. Site Config
        $this->siteConfigService->generate($config);

        // 6. Nacharbeiten
        $strategy->postProcess($config);
    }
}