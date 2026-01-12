<?php

defined('TYPO3') or die();

// set label here because label for empty key cannot be in altLabels PageTSConfig
$GLOBALS['TCA']['tt_content']['columns']['table_class']['config']['items'][0][0] = 'Basic (no style) responsive';

// remove "default" from dropdown, see packages/sitetum/Configuration/PageTs/SiteAll/tcadefaults.tsconfig
unset($GLOBALS['TCA']['tt_content']['columns']['header_layout']['config']['items'][0]);
// also remove "Hidden", we readd it in packages/sitetum/Configuration/PageTs/SiteAll/tcadefaults.tsconfig
// to push it to correct position
unset($GLOBALS['TCA']['tt_content']['columns']['header_layout']['config']['items'][6]);
