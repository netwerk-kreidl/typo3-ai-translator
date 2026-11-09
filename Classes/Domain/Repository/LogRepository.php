<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class LogRepository
{
    private const TABLE = 'tx_aitranslator_log';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @param array<string, int|string> $data
     */
    public function add(array $data): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $data + ['crdate' => time()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findLatest(int $limit = 200): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<string, array{runs: int, characters: int, input_tokens: int, output_tokens: int, cost: float}>
     */
    public function getTotalsByProvider(int $since): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb->select('provider')
            ->addSelectLiteral('COUNT(*) AS runs, SUM(characters) AS characters, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(cost) AS cost')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter('success')),
            )
            ->groupBy('provider')
            ->executeQuery()->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['provider']] = [
                'runs' => (int)$row['runs'],
                'characters' => (int)$row['characters'],
                'input_tokens' => (int)$row['input_tokens'],
                'output_tokens' => (int)$row['output_tokens'],
                'cost' => round((float)$row['cost'], 2),
            ];
        }
        return $result;
    }

    /**
     * Kosten (geschätzt, in Euro) seit Monatsbeginn.
     */
    public function getMonthCost(): float
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (float)$qb->addSelectLiteral('SUM(cost)')->from(self::TABLE)->where(
            $qb->expr()->gte('crdate', $qb->createNamedParameter((int)strtotime(date('Y-m-01 00:00:00')), Connection::PARAM_INT)),
        )->executeQuery()->fetchOne();
    }
}
