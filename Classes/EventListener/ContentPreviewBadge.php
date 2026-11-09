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
        $status = $this->statusService->getPageStatus($record->getPid(), $languageId);
        $outdated = $status->getOutdatedByTopRecord()['tt_content'] ?? [];
        $uids = array_unique(array_filter([$record->getUid(), $record instanceof Record ? $record->getOverlaidUid() : 0]));
        $badges = '';
        foreach ($uids as $uid) {
            if (isset($outdated[$uid])) {
                $fields = htmlspecialchars(implode(', ', $outdated[$uid]));
                $badges .= '<span class="badge badge-warning" title="Geändert: ' . $fields . '">Übersetzung veraltet</span> '
                    . '<span class="small text-body-secondary">Geändert: ' . $fields . '</span> ';
            }
            if ($status->isUnreviewed('tt_content', $uid)) {
                $badges .= '<span class="badge badge-info" title="Automatisch übersetzt und noch von niemandem gespeichert. Nach dem Prüfen einmal speichern.">KI-übersetzt – bitte prüfen</span> ';
            }
            if ($badges !== '') {
                break;
            }
        }
        if ($badges === '') {
            return;
        }
        $event->setPreviewContent('<p class="mb-2">' . trim($badges) . '</p>' . $event->getPreviewContent());
    }
}
