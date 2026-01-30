<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tum-webinfo-icon' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:webinfo/Resources/Public/Icons/module-webinfo.svg',
    ],
];
