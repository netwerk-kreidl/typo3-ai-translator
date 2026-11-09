<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Controller;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Domain\Repository\LogRepository;
use Netwerk\AiTranslator\Provider\LanguagePair;
use Netwerk\AiTranslator\Provider\ProviderRegistry;
use Netwerk\AiTranslator\Provider\Segment;
use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Provider\TranslationRequest;
use Netwerk\AiTranslator\Provider\TranslationStyle;
use Netwerk\AiTranslator\Queue\QueueRepository;
use Netwerk\AiTranslator\Security\Permission;
use Netwerk\AiTranslator\Service\RecordFinder;
use Netwerk\AiTranslator\Status\PageStatus;
use Netwerk\AiTranslator\Status\StatusService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Modul „KI-Übersetzung“
 *  - Übersicht: Übersetzungsstand aller Seiten je Sprache, Massenübersetzung über die Warteschlange
 *  - Anbieter & Protokoll: Status der Anbieter, Verbindungstest, Verbrauch und Protokoll
 */
#[AsController]
final readonly class ModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ProviderRegistry $providers,
        private ExtensionSettings $settings,
        private LogRepository $logRepository,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private SiteFinder $siteFinder,
        private RecordFinder $recordFinder,
        private StatusService $statusService,
        private QueueRepository $queue,
        private PageRenderer $pageRenderer,
    ) {}

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createView($request, 'overview');
        $query = $request->getQueryParams();

        $sites = $this->siteFinder->getAllSites();
        $site = $sites[(string)($query['site'] ?? '')] ?? reset($sites) ?: null;
        if (!$site instanceof Site) {
            return $view->renderResponse('Module/Overview');
        }
        $languages = [];
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() > 0 && Permission::canEditLanguage($language->getLanguageId())) {
                $languages[$language->getLanguageId()] = $language;
            }
        }
        $languageId = (int)($query['language'] ?? 0);
        $languageId = isset($languages[$languageId]) ? $languageId : (int)array_key_first($languages);
        $filter = (string)($query['filter'] ?? 'open');

        $pages = [];
        $summary = [PageStatus::STATE_NOT_TRANSLATED => 0, PageStatus::STATE_OUTDATED => 0, PageStatus::STATE_INCOMPLETE => 0, PageStatus::STATE_CURRENT => 0];
        if ($languageId > 0) {
            $openQueue = $this->queue->getOpenPages($languageId);
            $user = $GLOBALS['BE_USER'];
            foreach ($this->recordFinder->getPageTree($site->getRootPageId()) as $node) {
                $row = $node['row'];
                $uid = (int)$row['uid'];
                if (!$user->isInWebMount($uid)) {
                    continue;
                }
                $status = $this->statusService->getPageStatus($uid, $languageId);
                $state = $status->getState();
                // Ordner ohne übersetzbare Datensätze nicht anzeigen
                if ($status->isFolder && $state === PageStatus::STATE_CURRENT && $this->recordFinder->findPageRecords($uid) === []) {
                    continue;
                }
                $summary[$state]++;
                $pages[] = [
                    'uid' => $uid,
                    'row' => BackendUtility::getRecord('pages', $uid) ?? $row,
                    'title' => (string)$row['title'],
                    'depth' => $node['depth'],
                    'indent' => $node['depth'] * 1.25,
                    'state' => $state,
                    'visible' => $filter === 'all' || $state !== PageStatus::STATE_CURRENT,
                    'isFolder' => $status->isFolder,
                    'outdatedCount' => $status->getOutdatedCount(),
                    'outdated' => $status->outdated,
                    'missingCount' => count($status->missing),
                    'queued' => $openQueue[$uid] ?? '',
                    'link' => (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $uid, 'viewMode' => 2, 'languages' => [0, $languageId]]),
                ];
            }
        }

        $queueCounts = $this->queue->getCounts();
        $recent = [];
        foreach ($this->queue->findRecent(30) as $entry) {
            $entry['pageTitle'] = (string)(BackendUtility::getRecord('pages', (int)$entry['page_uid'], 'title')['title'] ?? '');
            $entry['languageTitle'] = isset($languages[(int)$entry['language']]) ? $languages[(int)$entry['language']]->getTitle() : (string)$entry['language'];
            $recent[] = $entry;
        }

        $providers = [];
        foreach ($this->providers->configured($this->settings->get('defaultProvider', 'deepl')) as $id => $provider) {
            $providers[$id] = $provider->getLabel();
        }

        $this->pageRenderer->loadJavaScriptModule('@netwerk/ai-translator/queue.js');
        $view->assignMultiple([
            'sites' => count($sites) > 1 ? $sites : [],
            'site' => $site,
            'languages' => $languages,
            'languageId' => $languageId,
            'filter' => $filter,
            'pages' => $pages,
            'summary' => $summary,
            'providers' => $providers,
            'canTranslate' => Permission::canTranslate() && $providers !== [],
            'queueCounts' => $queueCounts,
            'queueOpen' => $queueCounts[QueueRepository::PENDING] + $queueCounts[QueueRepository::RUNNING],
            'queueRecent' => $recent,
            'autostart' => (bool)($query['start'] ?? false),
            'enqueueUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator.enqueue'),
            'queueUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator.queue'),
            'selfUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator', ['site' => $site->getIdentifier(), 'language' => $languageId, 'filter' => $filter]),
        ]);
        return $view->renderResponse('Module/Overview');
    }

    /**
     * Ausgewählte Seiten in die Warteschlange stellen.
     */
    public function enqueueAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $languageId = (int)($body['language'] ?? 0);
        $provider = (string)($body['provider'] ?? '');
        $pageUids = array_values(array_filter(array_map(intval(...), (array)($body['pages'] ?? []))));
        $back = ['site' => (string)($body['site'] ?? ''), 'language' => $languageId, 'filter' => (string)($body['filter'] ?? 'open')];

        if (!Permission::canTranslate() || !Permission::canEditLanguage($languageId) || !$this->providers->has($provider)) {
            $this->addFlashMessage('Keine Berechtigung oder kein Anbieter gewählt.', ContextualFeedbackSeverity::ERROR, 'Massenübersetzung');
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_translator', $back));
        }
        if ($pageUids === []) {
            $this->addFlashMessage('Bitte mindestens eine Seite auswählen.', ContextualFeedbackSeverity::WARNING, 'Massenübersetzung');
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_translator', $back));
        }
        $added = $this->queue->add($pageUids, $languageId, $provider, (int)($GLOBALS['BE_USER']->user['uid'] ?? 0));
        $this->addFlashMessage(
            sprintf('%d Seiten wurden in die Warteschlange gestellt.', $added),
            ContextualFeedbackSeverity::INFO,
            'Massenübersetzung'
        );
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_translator', $back + ['start' => 1]));
    }

    /**
     * Warteschlange aufräumen bzw. offene Einträge abbrechen.
     */
    public function queueAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        if (Permission::canTranslate()) {
            match ((string)($body['action'] ?? '')) {
                'cancel' => $this->queue->cancelPending(),
                'clear' => $this->queue->clearFinished(),
                default => null,
            };
        }
        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($body['returnUrl'] ?? ''));
        return new RedirectResponse($returnUrl ?: (string)$this->uriBuilder->buildUriFromRoute('ai_translator'));
    }

    public function providersAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createView($request, 'providers');

        $providers = [];
        foreach ($this->providers->all() as $provider) {
            $id = $provider->getIdentifier();
            $providers[] = [
                'identifier' => $id,
                'label' => $provider->getLabel(),
                'configured' => $provider->isConfigured(),
                'fromEnvironment' => $this->settings->isFromEnvironment($id . 'ApiKey'),
                'isDefault' => $this->settings->get('defaultProvider', 'deepl') === $id,
                'model' => match ($id) {
                    'openai' => $this->settings->get('openaiModel'),
                    'anthropic' => $this->settings->get('anthropicModel') . ' · ' . $this->settings->get('anthropicEffort'),
                    default => '',
                },
            ];
        }

        $log = [];
        foreach ($this->logRepository->findLatest() as $entry) {
            $page = BackendUtility::getRecord('pages', (int)$entry['page_uid'], 'uid,title');
            $user = BackendUtility::getRecord('be_users', (int)$entry['be_user'], 'username,realName');
            $entry['pageTitle'] = $page['title'] ?? '';
            $entry['userName'] = ($user['realName'] ?? '') ?: ($user['username'] ?? '');
            $entry['pageLink'] = $page !== null
                ? (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $page['uid'], 'languages' => [(int)$entry['language']]])
                : '';
            $log[] = $entry;
        }

        $view->assignMultiple([
            'providers' => $providers,
            'totals' => $this->logRepository->getTotalsByProvider(strtotime('-30 days')),
            'log' => $log,
            'testUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator.test'),
            'isAdmin' => $GLOBALS['BE_USER']->isAdmin(),
        ]);
        return $view->renderResponse('Module/Providers');
    }

    /**
     * Verbindungstest: übersetzt einen kurzen Satz ins Englische.
     */
    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        $providerId = (string)($request->getParsedBody()['provider'] ?? '');
        try {
            $provider = $this->providers->get($providerId);
            $result = $provider->translate(new TranslationRequest(
                ['test' => new Segment('Herzlich willkommen im Hotel – wir freuen uns auf Ihren Besuch!')],
                new LanguagePair('de', 'en', 'GB', 'Deutsch', 'English (GB)'),
                new TranslationStyle('formal'),
            ));
            $this->addFlashMessage(
                sprintf('%s funktioniert: „%s“', $provider->getLabel(), $result->translations['test'] ?? ''),
                ContextualFeedbackSeverity::OK
            );
        } catch (TranslationException $e) {
            $this->addFlashMessage($e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_translator.providers'));
    }

    private function createView(ServerRequestInterface $request, string $current): ModuleTemplate
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('KI-Übersetzung');
        $view->assignMultiple([
            'nav' => [
                ['id' => 'overview', 'label' => 'Übersicht & Massenübersetzung', 'url' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator'), 'active' => $current === 'overview'],
                ['id' => 'providers', 'label' => 'Anbieter & Protokoll', 'url' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator.providers'), 'active' => $current === 'providers'],
            ],
        ]);
        return $view;
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity, string $title = 'Verbindungstest'): void
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($message, $title, $severity, true)
        );
    }
}
