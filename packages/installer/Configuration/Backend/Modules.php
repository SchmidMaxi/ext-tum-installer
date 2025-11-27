<?php

use Tum\Installer\Controller\BackendInstallerController;

return [
    'tum_installer' => [
        'parent' => 'system',           // Wir hängen uns in den Bereich "System"
        'position' => ['bottom'],       // Ganz unten
        'access' => 'admin',            // Nur für Admins sichtbar
        'workspaces' => 'live',
        'iconIdentifier' => 'module-install', // Standard Icon (oder eigenes definieren)
        'path' => '/module/system/installer',
        'labels' => 'LLL:EXT:installer/Resources/Private/Language/locallang_mod.xlf', // Titel & Beschreibung
        'extensionName' => 'Installer',
        'controllerActions' => [
            BackendInstallerController::class => [
                'index',    // Formular anzeigen
                'execute',  // Setup ausführen
            ],
        ],
    ],
];