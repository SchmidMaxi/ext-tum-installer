<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;

interface SetupStrategyInterface
{
    public function supports(SetupType $type): bool;
    public function prepare(InstallationConfig $config): InstallationConfig;
    public function getYamlFilePath(InstallationConfig $config): string;
    public function postProcess(InstallationConfig $config): void;
}