<?php

use Netwerk\AiTranslator\Controller\UpdateController;

return [
    'ai_translator_queue_process' => [
        'path' => '/ai-translator/queue/process',
        'target' => UpdateController::class . '::processQueueAction',
        'methods' => ['POST'],
    ],
];
