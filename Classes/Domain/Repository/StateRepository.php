<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Fingerabdrücke der Ausgangstexte zum Zeitpunkt der Übersetzung (Grundlage für „Übersetzung veraltet“).
 */
final class StateRepository
{
    private const TABLE = 'tx_aitranslator_state';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public static function hash(string $value): string
    {
        return md5(trim($value));
    }

    public function save(string $table, int $translationUid, int $sourceUid, int $pageUid, int $language, string $field, string $sourceValue, string $translatedValue, string $provider): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['tablename' => $table, 'translation_uid' => $translationUid, 'field' => $field]);
        $connection->insert(self::TABLE, [
            'tablename' => $table,
            'translation_uid' => $translationUid,
            'source_uid' => $sourceUid,
            'page_uid' => $pageUid,
            'language' => $language,
            'field' => $field,
            'source_hash' => self::hash($sourceValue),
            'translation_hash' => self::hash($translatedValue),
            'provider' => $provider,
            'tstamp' => time(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>> Feldname => Zustand
     */
    public function findByTranslation(string $table, int $translationUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb->select('*')->from(self::TABLE)->where(
            $qb->expr()->eq('tablename', $qb->createNamedParameter($table)),
            $qb->expr()->eq('translation_uid', $qb->createNamedParameter($translationUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
        )->executeQuery()->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['field']] = $row;
        }
        return $result;
    }
}
