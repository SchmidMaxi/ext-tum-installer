<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;

class StandaloneStrategy extends AbstractStrategy
{
    public function supports(SetupType $type): bool
    {
        return $type === SetupType::STANDALONE;
    }

    public function prepare(InstallationConfig $config): InstallationConfig
    {
        return $config->withUpdates([
            'targetPid' => 0,
            'uploadPath' => $this->getStandardUploadPath($config),
            'slugName' => $config->navName
        ]);
    }

    public function getYamlFilePath(InstallationConfig $config): string
    {
        // Nutzt Standalone.yaml (wie von dir erwähnt)
        return 'EXT:installer/Configuration/Installer/Standalone.yaml';
    }

    public function postProcess(InstallationConfig $config): void
    {
        $rootPageId = $this->findPageId($config->navName);
        if ($rootPageId === 0) return;

        $tsConfig = $this->generateStandardTsConfig($config, $rootPageId);
        $this->updateTsConfig($rootPageId, $tsConfig);
    }
}