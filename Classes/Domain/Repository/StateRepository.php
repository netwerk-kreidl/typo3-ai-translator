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

    public function save(string $table, int $translationUid, int $sourceUid, int $pageUid, int $language, string $field, string $sourceValue, string $translatedValue, string $provider, string $topTable = '', int $topUid = 0): void
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
            'top_tablename' => $topTable ?: $table,
            'top_uid' => $topUid ?: $translationUid,
            'reviewed' => 0,
        ]);
    }

    /**
     * Ein Redakteur hat die Übersetzung gespeichert – sie gilt als geprüft.
     */
    public function markReviewed(string $table, int $translationUid): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->update(self::TABLE)->set('reviewed', 1)->where(
            $qb->expr()->eq('reviewed', 0),
            $qb->expr()->or(
                $qb->expr()->and(
                    $qb->expr()->eq('tablename', $qb->createNamedParameter($table)),
                    $qb->expr()->eq('translation_uid', $qb->createNamedParameter($translationUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                ),
                $qb->expr()->and(
                    $qb->expr()->eq('top_tablename', $qb->createNamedParameter($table)),
                    $qb->expr()->eq('top_uid', $qb->createNamedParameter($translationUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                ),
            ),
        )->executeStatement();
    }

    /**
     * Alle KI-Übersetzungen einer Seite in einer Sprache als geprüft markieren.
     */
    public function markPageReviewed(int $pageUid, int $language): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$qb->update(self::TABLE)->set('reviewed', 1)->where(
            $qb->expr()->eq('reviewed', 0),
            $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            $qb->expr()->eq('language', $qb->createNamedParameter($language, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
        )->executeStatement();
    }

    /**
     * Noch nicht geprüfte KI-Übersetzungen einer Seite: oberste Datensätze (Tabelle => UIDs der Übersetzungen).
     *
     * @return array<string, array<int, true>>
     */
    public function findUnreviewed(int $pageUid, int $language): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = [];
        foreach ($qb->select('top_tablename', 'top_uid')->from(self::TABLE)->where(
            $qb->expr()->eq('reviewed', 0),
            $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            $qb->expr()->eq('language', $qb->createNamedParameter($language, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
        )->groupBy('top_tablename', 'top_uid')->executeQuery()->fetchAllAssociative() as $row) {
            $result[(string)$row['top_tablename']][(int)$row['top_uid']] = true;
        }
        return $result;
    }

    public function isUnreviewed(string $table, int $translationUid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$qb->count('*')->from(self::TABLE)->where(
            $qb->expr()->eq('reviewed', 0),
            $qb->expr()->eq('tablename', $qb->createNamedParameter($table)),
            $qb->expr()->eq('translation_uid', $qb->createNamedParameter($translationUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
        )->executeQuery()->fetchOne() > 0;
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
