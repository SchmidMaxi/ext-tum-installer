<?php

use Tum\Webinfo\Controller\ApiController;

return [
    'webinfo_api_create' => [
        'path' => '/webinfo/api/v1/installation',
        'methods' => ['POST'],
        'target' => ApiController::class . '::createAction',
    ],
    'webinfo_api_list' => [
        'path' => '/webinfo/api/v1/websites',
        'methods' => ['GET'],
        'target' => ApiController::class . '::listAction',
    ],
    'webinfo_api_get' => [
        'path' => '/webinfo/api/v1/website',
        'methods' => ['GET'],
        'target' => ApiController::class . '::getAction',
    ],
];
