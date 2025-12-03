<?php

use Tum\Installer\Controller\BackendInstallerController;

return [
    'tum_installer' => [
        'parent' => 'system',
        'position' => ['bottom'],
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'tum-installer-icon',
        'path' => '/module/system/installer',
        'labels' => 'LLL:EXT:installer/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'Installer',
        'controllerActions' => [
            BackendInstallerController::class => [
                'index',
                'execute',
            ],
        ],
    ],
];