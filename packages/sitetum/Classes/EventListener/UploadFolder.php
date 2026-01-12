<?php

namespace ElementareTeilchen\Sitetum\EventListener;

use TYPO3\CMS\Core\Resource\Event\BeforeFolderDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderMovedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFolderRenamedEvent;
use TYPO3\CMS\Core\Resource\Exception\InsufficientUserPermissionsException;

/**
 * Listener to prevent our my_uploads_folder to be deleted / renamed / moved
 * in the migration v6 -> v8 we added new uploadFolders to each pgrp-Group and configure them via PageTSconfig
 * the admins or editors should not be able to move/delete this upload folders, because the path to them is hard set in PageTSconfig
 * see Configuration/Services.yaml
 *
 * @author Franz Kugelmann
 */
final class UploadFolder
{
    /**
     * @throws InsufficientUserPermissionsException
     */
    public function __invoke(BeforeFolderRenamedEvent|BeforeFolderMovedEvent|BeforeFolderDeletedEvent $event)
    {
        if ($event->getFolder()->getName() === '_my_direct_uploads') {
            throw new InsufficientUserPermissionsException('This folder must not be deleted / moved / renamed');
        }
    }
}
