<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;

class Setup3Strategy extends AbstractStrategy
{
    public function supports(SetupType $type): bool
    {
        return $type === SetupType::SETUP3;
    }

    public function prepare(InstallationConfig $config): InstallationConfig
    {
        // TODO: Falls Setup 3 einen speziellen Ordner braucht (z.B. "Projekte"), hier Logik einfügen
        $targetPid = 0;

        return $config->withUpdates([
            'targetPid' => $targetPid,
            'uploadPath' => $this->getStandardUploadPath($config),
            'slugName' => $config->navName
        ]);
    }

    public function getYamlFilePath(InstallationConfig $config): string
    {
        return 'EXT:installer/Configuration/Installer/Setup3.yaml';
    }

    public function postProcess(InstallationConfig $config): void
    {
        $rootPageId = $this->findPageId($config->navName);
        if ($rootPageId === 0) return;

        $tsConfig = $this->generateStandardTsConfig($config, $rootPageId);
        $this->updateTsConfig($rootPageId, $tsConfig);
    }
}