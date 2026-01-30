<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TUM Webinfo',
    'description' => 'Zentrale Verwaltung von Website-Informationen mit API-Endpoint',
    'category' => 'be',
    'author' => 'TUM TYPO3 Team',
    'author_email' => 'typo3@tum.de',
    'state' => 'beta',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
