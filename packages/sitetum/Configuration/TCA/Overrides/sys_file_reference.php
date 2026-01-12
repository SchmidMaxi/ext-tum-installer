<?php

defined('TYPO3') or die();

// Do not add [Translate to en]: for title alt and description
$GLOBALS['TCA']['sys_file_reference']['columns']['title']['l10n_mode'] = '';
$GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['l10n_mode'] = '';
$GLOBALS['TCA']['sys_file_reference']['columns']['description']['l10n_mode'] = '';
