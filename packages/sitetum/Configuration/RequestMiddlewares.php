<?php

use ElementareTeilchen\Sitetum\Middleware\AlertRenderer;

return [
    'frontend' => [
        'elementareteilchen/alert-handler' => [
            'target' => AlertRenderer::class,
            'description' => '',
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
    ],
];
