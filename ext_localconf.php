<?php

defined('TYPO3') or die();

// Eigene Berechtigung „KI-Übersetzung verwenden“ (Benutzergruppen → Zugriffsrechte → Benutzerdefinierte Optionen)
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aitranslator'] = [
    'header' => 'LLL:EXT:ai_translator/Resources/Private/Language/locallang.xlf:permission.header',
    'items' => [
        'translate' => [
            'LLL:EXT:ai_translator/Resources/Private/Language/locallang.xlf:permission.translate',
            'ai-translator-handler',
            'LLL:EXT:ai_translator/Resources/Private/Language/locallang.xlf:permission.translate.description',
        ],
    ],
];
