<?php
declare(strict_types=1);

namespace Tum\Installer\Service\Worker;

use Tum\Installer\Domain\Model\InstallationConfig;
use TYPO3\CMS\Core\Resource\StorageRepository;

class FolderStructureService
{
    public function __construct(
        private readonly StorageRepository $storageRepository
    ) {}

    public function createStructure(InstallationConfig $config): void
    {
        $navName = strtolower($config->navName);
        $wid = strtolower($config->wid);

        if (empty($navName) || empty($wid)) return;

        try {
            $storage = $this->storageRepository->findByUid(1);
            if (!$storage) return;

            $rootFolder = $storage->getRootLevelFolder();
            $currentFolder = $rootFolder;

            // WID Ordner
            if (!$storage->hasFolder($wid, $rootFolder)) {
                $currentFolder = $storage->createFolder($wid, $rootFolder);
            } else {
                $currentFolder = $rootFolder->getSubfolder($wid);
            }

            // Projekt Ordner
            if (!$storage->hasFolder($navName, $currentFolder)) {
                $currentFolder = $storage->createFolder($navName, $currentFolder);
            } else {
                $currentFolder = $currentFolder->getSubfolder($navName);
            }

            // Upload Ordner
            if (!$storage->hasFolder('_my_direct_uploads', $currentFolder)) {
                $storage->createFolder('_my_direct_uploads', $currentFolder);
            }
        } catch (\Exception $e) {
            // Logging wäre hier gut
        }
    }
}