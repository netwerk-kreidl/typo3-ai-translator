<?php

use Netwerk\AiTranslator\Controller\UpdateController;

// Nicht an das Modul gebunden: auch Redakteure ohne Zugriff auf das Modul „KI-Übersetzung“ können
// aus dem Seiten-Modul heraus aktualisieren (Berechtigung wird im Controller geprüft).
return [
    'ai_translator_update' => [
        'path' => '/ai-translator/update',
        'target' => UpdateController::class . '::updateAction',
        'methods' => ['POST'],
    ],
    // Datei-Metadaten aus der Dateiliste übersetzen/aktualisieren (Link im Aktionsmenü)
    'ai_translator_file' => [
        'path' => '/ai-translator/file',
        'target' => UpdateController::class . '::fileAction',
    ],
];
