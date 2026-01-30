<?php

use Tum\Webinfo\Controller\BackendWebinfoController;

return [
    'tum_webinfo' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'tum-webinfo-icon',
        'path' => '/module/web/webinfo',
        'labels' => 'LLL:EXT:webinfo/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'Webinfo',
        'controllerActions' => [
            BackendWebinfoController::class => [
                'index',
                'detail',
            ],
        ],
    ],
];
