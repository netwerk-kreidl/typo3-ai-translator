<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Queue;

use Netwerk\AiTranslator\Cost\BudgetExceededException;
use Netwerk\AiTranslator\Service\TranslationService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Arbeitet die Warteschlange ab – Eintrag für Eintrag (aus dem Modul per Browser oder per CLI/Cronjob).
 */
final readonly class QueueProcessor
{
    public function __construct(
        private QueueRepository $queue,
        private TranslationService $translationService,
        private LoggerInterface $logger,
    ) {}

    /** Status, wenn das Monatslimit erreicht ist – die Verarbeitung soll dann anhalten */
    public const STATUS_BUDGET = 'budget';

    /**
     * Verarbeitet den nächsten Eintrag.
     *
     * @return array{uid: int, page: int, title: string, status: string, message: string}|null null, wenn die Warteschlange leer ist
     */
    public function processNext(): ?array
    {
        $item = $this->queue->claimNext();
        if ($item === null) {
            return null;
        }
        $uid = (int)$item['uid'];
        $pageUid = (int)$item['page_uid'];
        $isFile = ($item['tablename'] ?? 'pages') === 'sys_file';
        $title = $isFile
            ? (string)(BackendUtility::getRecord('sys_file', $pageUid, 'name')['name'] ?? ('Datei #' . $pageUid))
            : (string)(BackendUtility::getRecord('pages', $pageUid, 'title')['title'] ?? ('#' . $pageUid));
        try {
            if ($isFile) {
                $result = $this->translationService->updateFileMetadata($pageUid, (int)$item['language'], (string)$item['provider']);
                $message = match (true) {
                    $result['created'] => 'Metadaten übersetzt',
                    $result['fields'] > 0 => sprintf('%d Felder aktualisiert', $result['fields']),
                    default => 'Bereits aktuell bzw. keine Texte',
                };
            } else {
                $result = $this->translationService->updatePage($pageUid, (int)$item['language'], (string)$item['provider']);
                $message = match (true) {
                    $result['createdPage'] => sprintf('Seite angelegt, %d Datensätze übersetzt', $result['records']),
                    $result['records'] > 0 => sprintf('%d Datensätze aktualisiert', $result['records']),
                    default => 'Bereits aktuell',
                };
            }
            $this->queue->finish($uid, QueueRepository::DONE, $message);
            return ['uid' => $uid, 'page' => $pageUid, 'title' => $title, 'status' => QueueRepository::DONE, 'message' => $message];
        } catch (BudgetExceededException $e) {
            $this->queue->release($uid, $e->getMessage());
            return ['uid' => $uid, 'page' => $pageUid, 'title' => $title, 'status' => self::STATUS_BUDGET, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('AI translation of {table} {page} failed: {message}', ['table' => $item['tablename'] ?? 'pages', 'page' => $pageUid, 'message' => $e->getMessage(), 'exception' => $e]);
            $this->queue->finish($uid, QueueRepository::ERROR, $e->getMessage());
            return ['uid' => $uid, 'page' => $pageUid, 'title' => $title, 'status' => QueueRepository::ERROR, 'message' => $e->getMessage()];
        }
    }
}
