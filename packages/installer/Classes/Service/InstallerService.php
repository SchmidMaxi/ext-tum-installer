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
        private readonly ConnectionPool $connectionPool // NEU: Für DB-Checks
    ) {}

    /**
     * Prüft, ob das gewählte Setup durchgeführt werden darf.
     * Setup1 und Standalone dürfen nicht ausgeführt werden, wenn bereits
     * eine Root-Seite (außer Archiv) existiert.
     */
    public function isSetupAllowed(SetupType $type): bool
    {
        // Nur Setup1 und Standalone sind kritisch und schließen sich aus
        if ($type !== SetupType::SETUP1 && $type !== SetupType::STANDALONE) {
            return true;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $existingRoots = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', 0),
                $queryBuilder->expr()->eq('is_siteroot', 1),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($existingRoots as $root) {
            $title = $root['title'] ?? '';
            // Wir ignorieren Archive (beginnen meist mit Archiv-...)
            // Wenn eine andere Root Page da ist (z.B. Setup1, Standalone, Setup3), blockieren wir.
            if (!str_starts_with($title, 'Archiv')) {
                return false;
            }
        }

        return true;
    }

    public function install(InstallationConfig $initialConfig): void
    {
        // Sicherheitscheck auch hier nochmal
        if (!$this->isSetupAllowed($initialConfig->type)) {
            throw new \RuntimeException(
                sprintf('Installation von "%s" nicht möglich, da bereits eine Haupt-Instanz existiert.', $initialConfig->type->value)
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