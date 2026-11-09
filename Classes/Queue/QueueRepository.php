<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Queue;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Warteschlange für Massenübersetzungen.
 */
final class QueueRepository
{
    public const TABLE = 'tx_aitranslator_queue';
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const ERROR = 'error';

    /** Nach dieser Zeit gilt ein hängender Eintrag (z. B. abgebrochener Request) als wieder offen */
    private const STALE_AFTER = 900;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Fügt Seiten bzw. Dateien hinzu; bereits offene Einträge desselben Datensatzes und derselben Sprache werden übersprungen.
     *
     * @param list<int> $pageUids Seiten (tablename „pages“) bzw. Dateien („sys_file“)
     * @return int Anzahl neuer Einträge
     */
    public function add(array $pageUids, int $languageId, string $provider, int $beUser, string $tablename = 'pages'): int
    {
        $open = [];
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        foreach ($qb->select('page_uid')->from(self::TABLE)->where(
            $qb->expr()->eq('tablename', $qb->createNamedParameter($tablename)),
            $qb->expr()->eq('language', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
            $qb->expr()->in('status', $qb->createNamedParameter([self::PENDING, self::RUNNING], Connection::PARAM_STR_ARRAY)),
        )->executeQuery()->fetchFirstColumn() as $uid) {
            $open[(int)$uid] = true;
        }
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $added = 0;
        foreach (array_unique($pageUids) as $pageUid) {
            if (isset($open[$pageUid])) {
                continue;
            }
            $connection->insert(self::TABLE, [
                'tablename' => $tablename,
                'crdate' => time(),
                'tstamp' => time(),
                'be_user' => $beUser,
                'page_uid' => $pageUid,
                'language' => $languageId,
                'provider' => $provider,
                'status' => self::PENDING,
                'message' => '',
            ]);
            $added++;
        }
        return $added;
    }

    /**
     * Holt den nächsten offenen Eintrag und markiert ihn als „in Arbeit“ (sicher bei parallelen Aufrufen).
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $this->releaseStale();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $row = $qb->select('*')->from(self::TABLE)
                ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::PENDING)))
                ->orderBy('uid')
                ->setMaxResults(1)
                ->executeQuery()->fetchAssociative();
            if ($row === false) {
                return null;
            }
            $claimed = $connection->update(
                self::TABLE,
                ['status' => self::RUNNING, 'tstamp' => time()],
                ['uid' => (int)$row['uid'], 'status' => self::PENDING],
            );
            if ($claimed === 1) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Eintrag wieder freigeben (z. B. Monatslimit erreicht) – er wird später erneut verarbeitet.
     */
    public function release(int $uid, string $message = ''): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['status' => self::PENDING, 'message' => mb_substr($message, 0, 2000), 'tstamp' => time()],
            ['uid' => $uid],
        );
    }

    public function finish(int $uid, string $status, string $message = ''): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['status' => $status, 'message' => mb_substr($message, 0, 2000), 'tstamp' => time()],
            ['uid' => $uid],
        );
    }

    /**
     * Setzt hängengebliebene Einträge wieder auf „offen“.
     */
    public function releaseStale(): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->update(self::TABLE)
            ->set('status', self::PENDING)
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter(self::RUNNING)),
                $qb->expr()->lt('tstamp', $qb->createNamedParameter(time() - self::STALE_AFTER, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * @return array{pending: int, running: int, done: int, error: int}
     */
    public function getCounts(): array
    {
        $counts = [self::PENDING => 0, self::RUNNING => 0, self::DONE => 0, self::ERROR => 0];
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        foreach ($qb->select('status')->addSelectLiteral('COUNT(*) AS cnt')->from(self::TABLE)
            ->groupBy('status')->executeQuery()->fetchAllAssociative() as $row) {
            $counts[(string)$row['status']] = (int)$row['cnt'];
        }
        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $limit = 50): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<int, string> Seiten- bzw. Datei-UID => Status der offenen Einträge einer Sprache
     */
    public function getOpenPages(int $languageId, string $tablename = 'pages'): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = [];
        foreach ($qb->select('page_uid', 'status')->from(self::TABLE)->where(
            $qb->expr()->eq('tablename', $qb->createNamedParameter($tablename)),
            $qb->expr()->eq('language', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
            $qb->expr()->in('status', $qb->createNamedParameter([self::PENDING, self::RUNNING], Connection::PARAM_STR_ARRAY)),
        )->executeQuery()->fetchAllAssociative() as $row) {
            $result[(int)$row['page_uid']] = (string)$row['status'];
        }
        return $result;
    }

    /**
     * Entfernt erledigte und fehlerhafte Einträge (offene bleiben).
     */
    public function clearFinished(): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->delete(self::TABLE)
            ->where($qb->expr()->in('status', $qb->createNamedParameter([self::DONE, self::ERROR], Connection::PARAM_STR_ARRAY)))
            ->executeStatement();
    }

    /**
     * Bricht alle offenen Einträge ab.
     */
    public function cancelPending(): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::PENDING)))
            ->executeStatement();
    }
}
