<?php

defined('TYPO3') || die();

// see packages/sitetum/Resources/Private/Extensions/FluidStyledContent/Partials/Media/Rendering/Image.html
$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] .= ',webp';

$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['sitetum'] = 'EXT:sitetum/Configuration/RTE/Editor/sitetum.yaml';
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['sitetum-extended'] = 'EXT:sitetum/Configuration/RTE/Editor/sitetum-extended.yaml';
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['sitetum-admin'] = 'EXT:sitetum/Configuration/RTE/Editor/sitetum-admin.yaml';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['EXT:dp_cookieconsent/Resources/Private/Language/locallang.xlf'][] = 'EXT:sitetum/Resources/Private/Language/Extensions/DpCookieconsent/locallang.xlf';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['de']['EXT:dp_cookieconsent/Resources/Private/Language/locallang.xlf'][] = 'EXT:sitetum/Resources/Private/Language/Extensions/DpCookieconsent/de.locallang.xlf';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['EXT:media2click/Resources/Private/Language/locallang.xlf'][] = 'EXT:sitetum/Resources/Private/Language/Extensions/Media2Click/locallang.xlf';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['de']['EXT:media2click/Resources/Private/Language/locallang.xlf'][] = 'EXT:sitetum/Resources/Private/Language/Extensions/Media2Click/de.locallang.xlf';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['EXT:frontend/Resources/Private/Language/Database.xlf'][] = 'EXT:sitetum/Resources/Private/Language/be_locallang.xlf';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['de']['EXT:frontend/Resources/Private/Language/Database.xlf'][] = 'EXT:sitetum/Resources/Private/Language/de.be_locallang.xlf';

// This prevents typo3_encore from removing the resources' leading slash if absRefPath is "/"
$GLOBALS['TYPO3_CONF_VARS']['FE']['additionalAbsRefPrefixDirectories']
    = ($GLOBALS['TYPO3_CONF_VARS']['FE']['additionalAbsRefPrefixDirectories']
        ? $GLOBALS['TYPO3_CONF_VARS']['FE']['additionalAbsRefPrefixDirectories'] . ',_frontend'
        : '_frontend');

// Add custom rte css file
$GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['sitetumFullscreen'] = 'EXT:sitetum/Resources/Public/Javascript/Ckeditor/fullscreen/fullscreen.css';
$GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['rteTableCell'] = 'EXT:sitetum/Resources/Public/Stylesheets/rte-hide-table-options.css';

// Add these parameters (for our google search) to avoid triggering a 404 because no cHash is given
array_push($GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'], 'q', 'sites');
