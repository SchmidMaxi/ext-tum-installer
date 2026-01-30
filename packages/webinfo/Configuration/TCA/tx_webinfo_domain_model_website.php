<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:webinfo/Resources/Private/Language/locallang_db.xlf:tx_webinfo_domain_model_website',
        'label' => 'url',
        'label_alt' => 'domain,wid',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'searchFields' => 'url,domain,nav_name,wid,setup,umgebung,organization_unit,website_type,typo3_version,note',
        'iconfile' => 'EXT:webinfo/Resources/Public/Icons/website.svg',
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    url, domain, nav_name, wid, setup,
                --div--;Webinfo,
                    umgebung, organization_unit, website_type, typo3_version,
                --div--;Laufzeit,
                    created_at, valid_until, after_expiry,
                --div--;Notizen,
                    note,
            ',
        ],
    ],
    'columns' => [
        'url' => [
            'label' => 'URL',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 500,
                'eval' => 'trim',
            ],
        ],
        'domain' => [
            'label' => 'Domain',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'nav_name' => [
            'label' => 'Nav Name',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'eval' => 'trim',
            ],
        ],
        'wid' => [
            'label' => 'WID',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'max' => 20,
                'eval' => 'trim',
            ],
        ],
        'setup' => [
            'label' => 'Setup Type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Setup1', 'value' => 'Setup1'],
                    ['label' => 'Setup3', 'value' => 'Setup3'],
                    ['label' => 'Standalone', 'value' => 'Standalone'],
                    ['label' => 'Archiv', 'value' => 'Archiv'],
                ],
            ],
        ],
        'umgebung' => [
            'label' => 'Umgebung',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'www-v23', 'value' => 'www-v23'],
                    ['label' => 'www-v19', 'value' => 'www-v19'],
                    ['label' => 'www-v8', 'value' => 'www-v8'],
                ],
            ],
        ],
        'organization_unit' => [
            'label' => 'Organization Unit',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'website_type' => [
            'label' => 'Website Type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'Einrichtung', 'value' => 'Einrichtung'],
                    ['label' => 'Forschungsgruppe', 'value' => 'Forschungsgruppe'],
                    ['label' => 'Kooperation', 'value' => 'Kooperation'],
                    ['label' => 'Projekt', 'value' => 'Projekt'],
                    ['label' => 'Studiengang', 'value' => 'Studiengang'],
                    ['label' => 'Sonstiges', 'value' => 'Sonstiges'],
                ],
            ],
        ],
        'typo3_version' => [
            'label' => 'TYPO3 Version',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'v12', 'value' => 'v12'],
                    ['label' => 'v14', 'value' => 'v14'],
                ],
            ],
        ],
        'created_at' => [
            'label' => 'In TYPO3 seit',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
            ],
        ],
        'valid_until' => [
            'label' => 'Laufzeit bis',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
            ],
        ],
        'after_expiry' => [
            'label' => 'Nach Laufzeitende',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                    ['label' => 'Archiv', 'value' => 'Archiv'],
                    ['label' => 'Löschen', 'value' => 'Löschen'],
                    ['label' => 'Verlängern', 'value' => 'Verlängern'],
                ],
            ],
        ],
        'note' => [
            'label' => 'Notiz',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
            ],
        ],
    ],
];
