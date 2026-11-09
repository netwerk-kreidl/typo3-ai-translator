<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Controller;

use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Queue\QueueProcessor;
use Netwerk\AiTranslator\Queue\QueueRepository;
use Netwerk\AiTranslator\Security\Permission;
use Netwerk\AiTranslator\Service\TranslationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Aktualisieren einer Seite aus dem Seiten-Modul und Abarbeiten der Warteschlange aus dem Modul (AJAX).
 */
#[AsController]
final readonly class UpdateController
{
    public function __construct(
        private TranslationService $translationService,
        private QueueProcessor $queueProcessor,
        private QueueRepository $queue,
        private FlashMessageService $flashMessageService,
        private UriBuilder $uriBuilder,
    ) {}

    public function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $pageUid = (int)($body['page'] ?? 0);
        $languageId = (int)($body['language'] ?? 0);
        $provider = (string)($body['provider'] ?? '');
        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($body['returnUrl'] ?? ''))
            ?: (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);

        if (!Permission::canTranslate() || !Permission::canEditLanguage($languageId) || $pageUid <= 0 || $languageId <= 0) {
            $this->flash('Keine Berechtigung für die KI-Übersetzung.', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($returnUrl);
        }
        try {
            $result = $this->translationService->updatePage($pageUid, $languageId, $provider);
            $this->flash(
                $result['records'] > 0
                    ? sprintf('%d Datensätze wurden nachübersetzt. Bitte das Ergebnis kontrollieren.', $result['records'])
                    : 'Die Übersetzung war bereits aktuell.',
                ContextualFeedbackSeverity::OK
            );
        } catch (TranslationException $e) {
            $this->flash($e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return new RedirectResponse($returnUrl);
    }

    /**
     * Übersetzt die Metadaten einer Datei in eine oder alle Sprachen (aus der Dateiliste).
     */
    public function fileAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $fileUid = (int)($params['file'] ?? 0);
        $provider = (string)($params['provider'] ?? '');
        $languageIds = array_filter(array_map(intval(...), (array)($params['languages'] ?? [])));
        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($params['returnUrl'] ?? ''))
            ?: (string)$this->uriBuilder->buildUriFromRoute('media_management');

        if (!Permission::canTranslate() || $fileUid <= 0 || $languageIds === []) {
            $this->flash('Keine Berechtigung für die KI-Übersetzung.', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($returnUrl);
        }
        $done = 0;
        foreach ($languageIds as $languageId) {
            if (!Permission::canEditLanguage($languageId)) {
                continue;
            }
            try {
                $result = $this->translationService->updateFileMetadata($fileUid, $languageId, $provider);
                $done += $result['created'] || $result['fields'] > 0 ? 1 : 0;
            } catch (TranslationException $e) {
                $this->flash($e->getMessage(), ContextualFeedbackSeverity::ERROR);
                return new RedirectResponse($returnUrl);
            }
        }
        $this->flash(
            $done > 0 ? 'Die Metadaten wurden übersetzt. Bitte den Alternativtext kontrollieren.' : 'Die Übersetzung der Metadaten war bereits aktuell.',
            ContextualFeedbackSeverity::OK
        );
        return new RedirectResponse($returnUrl);
    }

    /**
     * Verarbeitet den nächsten Eintrag der Warteschlange und meldet den Stand zurück.
     */
    public function processQueueAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Permission::canTranslate()) {
            return new JsonResponse(['error' => 'Keine Berechtigung für die KI-Übersetzung.'], 403);
        }
        $result = $this->queueProcessor->processNext();
        return new JsonResponse([
            'item' => $result,
            'counts' => $this->queue->getCounts(),
        ]);
    }

    private function flash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($message, 'KI-Übersetzung', $severity, true)
        );
    }
}
