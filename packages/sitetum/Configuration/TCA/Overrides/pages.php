<?php

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::registerPageTSConfigFile(
    'sitetum',
    'Configuration/PageTs/BackendLayouts/Pagetree/c_intro1col.tsconfig',
    'EXT:sitetum :: use intro layout on this page'
);

ExtensionManagementUtility::registerPageTSConfigFile(
    'sitetum',
    'Configuration/PageTs/BackendLayouts/Pagetree/c_intro2col.tsconfig',
    'EXT:sitetum :: use intro2col layout on this page'
);
