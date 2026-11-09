<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\EventListener;

use Netwerk\AiTranslator\Configuration\ExtensionSettings;
use Netwerk\AiTranslator\Provider\ProviderRegistry;
use Netwerk\AiTranslator\Security\Permission;
use Netwerk\AiTranslator\Status\PageStatus;
use Netwerk\AiTranslator\Status\StatusService;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Hinweis im Modul „Seite“: Übersetzungen dieser Seite, deren deutscher Text sich geändert hat,
 * mit Schaltfläche zum Nachübersetzen.
 */
#[AsEventListener('ai-translator/page-layout-hint')]
final readonly class PageLayoutHint
{
    public function __construct(
        private StatusService $statusService,
        private SiteFinder $siteFinder,
        private ProviderRegistry $providers,
        private ExtensionSettings $settings,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $page = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid) : null;
        if ($page === null || (int)$page['sys_language_uid'] !== 0) {
            return;
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return;
        }

        $languages = [];
        foreach ($site->getLanguages() as $language) {
            $languageId = $language->getLanguageId();
            if ($languageId === 0 || !Permission::canEditLanguage($languageId)) {
                continue;
            }
            $status = $this->statusService->getPageStatus($pageUid, $languageId);
            // Fehlende Seitenübersetzungen zeigt TYPO3 selbst an
            $needsUpdate = $status->needsUpdate() && $status->getState() !== PageStatus::STATE_NOT_TRANSLATED;
            if (!$needsUpdate && $status->getUnreviewedCount() === 0) {
                continue;
            }
            $languages[] = [
                'id' => $languageId,
                'title' => $language->getTitle(),
                'flag' => $language->getFlagIdentifier(),
                'needsUpdate' => $needsUpdate,
                'outdated' => $status->outdated,
                'outdatedCount' => $status->getOutdatedCount(),
                'missing' => $status->missing,
                'unreviewedCount' => $status->getUnreviewedCount(),
            ];
        }
        if ($languages === []) {
            return;
        }

        $canTranslate = Permission::canTranslate();
        $providers = [];
        if ($canTranslate) {
            foreach ($this->providers->configured($this->settings->get('defaultProvider', 'deepl')) as $id => $provider) {
                $providers[$id] = $provider->getLabel();
            }
        }
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:ai_translator/Resources/Private/Templates/'],
            request: $request,
        ));
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'languages' => $languages,
            'providers' => $providers,
            'canTranslate' => $canTranslate && $providers !== [],
            'updateUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator_update'),
            'reviewUrl' => (string)$this->uriBuilder->buildUriFromRoute('ai_translator_review'),
            'returnUrl' => (string)$request->getUri(),
        ]);
        $event->addHeaderContent($view->render('PageLayout/Hint'));
    }
}
