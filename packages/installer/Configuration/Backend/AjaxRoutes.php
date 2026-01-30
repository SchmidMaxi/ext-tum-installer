<?php

use Tum\Installer\Controller\AjaxController;

return [
    'installer_progress' => [
        'path' => '/installer/progress',
        'target' => AjaxController::class . '::progressAction',
    ],
    'installer_execute' => [
        'path' => '/installer/execute',
        'target' => AjaxController::class . '::executeAction',
    ],
];
