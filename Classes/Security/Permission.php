<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Security;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Berechtigung „KI-Übersetzung verwenden“ (Benutzergruppen → Benutzerdefinierte Optionen).
 */
final class Permission
{
    public const CUSTOM_OPTION = 'tx_aitranslator:translate';

    public static function canTranslate(?BackendUserAuthentication $user = null): bool
    {
        $user ??= $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || $user->user === null) {
            return false;
        }
        return $user->isAdmin() || $user->check('custom_options', self::CUSTOM_OPTION);
    }

    public static function canEditLanguage(int $languageId, ?BackendUserAuthentication $user = null): bool
    {
        $user ??= $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication && $user->checkLanguageAccess($languageId);
    }
}
