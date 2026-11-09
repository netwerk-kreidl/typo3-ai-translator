<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Review;

use Netwerk\AiTranslator\Domain\Repository\StateRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Speichert ein Redakteur eine KI-Übersetzung (z. B. im Formular), gilt sie als geprüft.
 */
final class DataHandlerHook
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($status !== 'update' || ReviewGuard::isTranslating() || !is_numeric($id)) {
            return;
        }
        GeneralUtility::makeInstance(StateRepository::class)->markReviewed($table, (int)$id);
    }
}
