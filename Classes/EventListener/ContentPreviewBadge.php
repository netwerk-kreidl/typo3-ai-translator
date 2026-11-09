<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\EventListener;

use Netwerk\AiTranslator\Status\StatusService;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Domain\Record;

/**
 * Kennzeichnet im Modul „Seite“ übersetzte Inhaltselemente, deren Ausgangstext sich geändert hat.
 */
#[AsEventListener('ai-translator/content-preview-badge')]
final readonly class ContentPreviewBadge
{
    public function __construct(private StatusService $statusService) {}

    public function __invoke(AfterPageContentPreviewRenderedEvent $event): void
    {
        if ($event->getTable() !== 'tt_content') {
            return;
        }
        $record = $event->getRecord();
        $languageId = $record instanceof Record ? (int)$record->getLanguageId() : (int)($record->toArray()['sys_language_uid'] ?? 0);
        if ($languageId <= 0) {
            return;
        }
        $outdated = $this->statusService->getPageStatus($record->getPid(), $languageId)->getOutdatedByTopRecord()['tt_content'] ?? [];
        $uid = $record->getUid();
        if (!isset($outdated[$uid]) && $record instanceof Record) {
            $uid = $record->getOverlaidUid();
        }
        if (!isset($outdated[$uid])) {
            return;
        }
        $fields = htmlspecialchars(implode(', ', $outdated[$uid]));
        $event->setPreviewContent(
            '<p class="mb-2"><span class="badge badge-warning" title="Geändert: ' . $fields . '">'
            . 'Übersetzung veraltet</span> <span class="small text-body-secondary">Geändert: ' . $fields . '</span></p>'
            . $event->getPreviewContent()
        );
    }
}
