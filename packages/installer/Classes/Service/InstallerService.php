<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Strategy\SetupStrategyInterface;
use Tum\Installer\Service\Worker\DatabaseImporterService;
use Tum\Installer\Service\Worker\FolderStructureService;
use Tum\Installer\Service\Worker\SiteConfigurationService;
use Tum\Installer\Service\Worker\YamlLoaderService;

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
        private readonly FolderStructureService $folderService
    ) {}

    public function install(InstallationConfig $initialConfig): void
    {
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