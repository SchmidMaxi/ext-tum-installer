<?php

declare(strict_types=1);

namespace ElementareTeilchen\Siteloader\TsConfig;

/*
 * This file is part of TYPO3 CMS-based extension "bolt" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use ElementareTeilchen\Siteloader\Configuration\PackageHelper;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\Event\ModifyLoadedPageTsConfigEvent as LegacyModifyLoadedPageTsConfigEvent;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Event\ModifyLoadedPageTsConfigEvent;

/**
 * Dynamically loads PageTSconfig from an extension. Is added right before the backend fields
 * The File needs to be put into
 * EXT:site_mysite/Configuration/PageTsConfig/main.tsconfig
 */
class Loader
{
    /**
     * @var PackageHelper
     */
    protected $packageHelper;

    public function __construct(PackageHelper $packageHelper)
    {
        $this->packageHelper = $packageHelper;
    }

    public function addSiteConfigurationCore11(LegacyModifyLoadedPageTsConfigEvent $event): void
    {
        if (class_exists(ModifyLoadedPageTsConfigEvent::class)) {
            // TYPO3 v12 calls both old and new event. Check for class existence of new event to
            // skip handling of old event in v12, but continue to work with < v12.
            // Simplify this construct when v11 compat is dropped, clean up Services.yaml.
            return;
        }
        $event->setTsConfig(
            $this->findAndAddConfiguration(
                $event->getRootLine(),
                $event->getTsConfig()
            )
        );
    }

    public function addSiteConfiguration(ModifyLoadedPageTsConfigEvent $event): void
    {
        $event->setTsConfig(
            $this->findAndAddConfiguration(
                $event->getRootLine(),
                $event->getTsConfig()
            )
        );
    }

    protected function findAndAddConfiguration(array $rootLine, array $tsConfig): array
    {
        // run through the rootline (starts with 0) and find site packages
        // the deepest in tree is used in the end
        foreach ($rootLine as $pageRecord) {
            $package = $this->packageHelper->getSitePackage((int)$pageRecord['uid']);
            if ($package === null && ($pageRecord['is_siteroot'] ?? false)) {
                // Translations of site roots will yield no $package when looking by root page or pageId
                $fullPageRecord = BackendUtility::getRecord('pages', (int)$pageRecord['uid']);
                $transOrigPointerField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent';
                if ($fullPageRecord[$transOrigPointerField] ?? false) {
                    $package = $this->packageHelper->getSitePackage((int)$fullPageRecord[$transOrigPointerField]);
                }
            }
            if ($package !== null) {
                $tsConfigFile = $package->getPackagePath() . 'Configuration/PageTs/main.tsconfig';
                if (file_exists($tsConfigFile)) {
                    $fileContents = @file_get_contents($tsConfigFile);
                }
            }
        }

        if (!empty($fileContents)) {
            // now append the site package PageTs to pagesTsConfig-globals-defaultPageTSconfig
            // because it should be loaded right after it, adding it as own element in $tsConfig does not work
            // because the sorting set here is not kept after the event
            // TODO v14: key probably won't exist anymore
            $tsConfig['pagesTsConfig-globals-defaultPageTSconfig'] .= "\n" . $fileContents;
        }

        return $tsConfig;
    }
}
