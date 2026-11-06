<?php

use Netwerk\AiTranslator\Controller\ModuleController;

return [
    'ai_translator' => [
        'parent' => 'content',
        'access' => 'user',
        'path' => '/module/content/ai-translator',
        'iconIdentifier' => 'ai-translator-module',
        'labels' => 'LLL:EXT:ai_translator/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => ['target' => ModuleController::class . '::indexAction'],
            'test' => ['target' => ModuleController::class . '::testAction', 'methods' => ['POST']],
        ],
    ],
];
