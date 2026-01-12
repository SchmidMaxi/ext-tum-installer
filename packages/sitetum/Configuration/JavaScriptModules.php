<?php

// ET-SK: see cms-rte-ckeditor/Configuration/JavaScriptModules.php
return [
    // Load our JS modules after those from rte_ckeditor, since our RTE plugin has imports from these modules
    'dependencies' => [
        'rte_ckeditor',
    ],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@sitetum/rte-ckeditor/plugin/fullscreen.js' => 'EXT:sitetum/Resources/Public/Javascript/Ckeditor/fullscreen/fullscreen.js',
        '@sitetum/rte-ckeditor/plugin/linkablebrs.js' => 'EXT:sitetum/Resources/Public/Javascript/Ckeditor/custom_linkablebrs/js/ckeditor5_plugins/linkablebrs/src/index.js',
        '@sitetum/rte-ckeditor/plugin/bookmark-plugin' => 'EXT:sitetum/Resources/Public/Javascript/Ckeditor/bookmark/bookmark.js',
    ],
];
