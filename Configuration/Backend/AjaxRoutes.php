<?php

use Netwerk\AiTranslator\Controller\UpdateController;

return [
    'ai_translator_queue_process' => [
        'path' => '/ai-translator/queue/process',
        'target' => UpdateController::class . '::processQueueAction',
        'methods' => ['POST'],
    ],
    // Kostenschätzung für die Auswahl im Modul
    'ai_translator_estimate' => [
        'path' => '/ai-translator/estimate',
        'target' => UpdateController::class . '::estimateAction',
        'methods' => ['POST'],
    ],
];
