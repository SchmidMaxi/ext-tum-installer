<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;

class ArchivStrategy extends AbstractStrategy
{
    public function supports(SetupType $type): bool
    {
        return $type === SetupType::ARCHIV;
    }

    public function prepare(InstallationConfig $config): InstallationConfig
    {
        $targetPid = $this->findArchivParentId($config->parentOu, $config->department);
        if ($targetPid === 0) {
            throw new \RuntimeException("Archiv: Parent Page nicht gefunden!");
        }

        $parts = array_filter([$config->parentOu, $config->department, $config->navName]);
        $newNavName = implode('-', $parts); // school-dept-kürzel

        $centralArchivWid = 'w00archiv';
        $uploadPath = "1:{$centralArchivWid}/{$newNavName}/_my_direct_uploads/";

        return $config->withUpdates([
            'navName' => $newNavName,
            'wid' => $centralArchivWid,
            'targetPid' => $targetPid,
            'uploadPath' => $uploadPath,
            'slugName' => $newNavName
        ]);
    }

    public function getYamlFilePath(InstallationConfig $config): string
    {
        return 'EXT:installer/Configuration/Installer/Archiv.yaml';
    }

    public function postProcess(InstallationConfig $config): void
    {
        $rootPageId = $this->findPageId($config->navName);
        if ($rootPageId === 0) return;

        // Nutzt auch Standard TSConfig, aber mit neuem UploadPath
        $tsConfig = $this->generateStandardTsConfig($config, $rootPageId);
        $this->updateTsConfig($rootPageId, $tsConfig);
    }

    private function findArchivParentId(string $school, string $department): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $slugSchool = '/' . ltrim(strtolower($school), '/');

        $schoolUid = (int)$qb->select('uid')->from('pages')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slugSchool)))
            ->andWhere($qb->expr()->eq('sys_language_uid', 0))
            ->executeQuery()->fetchOne();

        if (!$schoolUid) return 0;
        if (empty($department)) return $schoolUid;

        $slugDept = $slugSchool . '/' . ltrim(strtolower($department), '/');
        $deptUid = (int)$qb->select('uid')->from('pages')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slugDept)))
            ->executeQuery()->fetchOne();

        return $deptUid ?: 0;
    }
}