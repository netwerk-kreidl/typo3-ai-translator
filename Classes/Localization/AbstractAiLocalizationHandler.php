<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Localization;

use Netwerk\AiTranslator\Provider\ProviderRegistry;
use Netwerk\AiTranslator\Provider\TranslationException;
use Netwerk\AiTranslator\Security\Permission;
use Netwerk\AiTranslator\Service\TranslationService;
use TYPO3\CMS\Backend\Localization\Finisher\RedirectLocalizationFinisher;
use TYPO3\CMS\Backend\Localization\Finisher\ReloadLocalizationFinisher;
use TYPO3\CMS\Backend\Localization\LocalizationHandlerInterface;
use TYPO3\CMS\Backend\Localization\LocalizationInstructions;
use TYPO3\CMS\Backend\Localization\LocalizationResult;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Zusätzliche Option im TYPO3-Übersetzen-Dialog („KI-Übersetzung mit …“), je Anbieter eine.
 * Nutzt die Localization-Handler-API von TYPO3 14 (noch @internal – bewusst nur in dieser Klasse gekapselt).
 */
abstract class AbstractAiLocalizationHandler implements LocalizationHandlerInterface
{
    public const PERMISSION = Permission::CUSTOM_OPTION;

    public function __construct(
        protected readonly ProviderRegistry $providers,
        protected readonly TranslationService $translationService,
        protected readonly UriBuilder $uriBuilder,
    ) {}

    abstract protected function getProviderIdentifier(): string;

    public function getIdentifier(): string
    {
        return 'ai_translator_' . $this->getProviderIdentifier();
    }

    public function getLabel(): string
    {
        return sprintf(
            $this->sL('LLL:EXT:ai_translator/Resources/Private/Language/locallang.xlf:handler.label') ?: 'KI-Übersetzung mit %s',
            $this->providers->get($this->getProviderIdentifier())->getLabel()
        );
    }

    public function getDescription(): string
    {
        return 'LLL:EXT:ai_translator/Resources/Private/Language/locallang.xlf:handler.description';
    }

    public function getIconIdentifier(): string
    {
        return 'ai-translator-handler';
    }

    public function isAvailable(LocalizationInstructions $instructions): bool
    {
        if (!Permission::canTranslate()) {
            return false;
        }
        if (!$this->providers->has($this->getProviderIdentifier()) || !$this->providers->get($this->getProviderIdentifier())->isConfigured()) {
            return false;
        }
        return in_array($instructions->mainRecordType, ['pages', 'tt_content', 'sys_file_metadata'], true) && $instructions->targetLanguageId > 0;
    }

    public function processLocalization(LocalizationInstructions $instructions): LocalizationResult
    {
        try {
            if ($instructions->mainRecordType === 'pages') {
                $contentUids = array_map(intval(...), (array)($instructions->additionalData['selectedRecordUids'] ?? []));
                $this->translationService->translatePage(
                    $instructions->recordUid,
                    $instructions->targetLanguageId,
                    $contentUids,
                    $this->getProviderIdentifier(),
                    $instructions->mode
                );
                return LocalizationResult::success(new RedirectLocalizationFinisher(
                    (string)$this->uriBuilder->buildUriFromRoute('web_layout', [
                        'id' => $instructions->recordUid,
                        'languages' => [$instructions->targetLanguageId],
                    ])
                ));
            }
            $this->translationService->translateRecord(
                $instructions->mainRecordType,
                $instructions->recordUid,
                $instructions->targetLanguageId,
                $this->getProviderIdentifier(),
                $instructions->mode
            );
            return LocalizationResult::success(new ReloadLocalizationFinisher());
        } catch (TranslationException $e) {
            return LocalizationResult::error([$e->getMessage()]);
        }
    }

    private function sL(string $key): string
    {
        return isset($GLOBALS['LANG']) ? (string)$GLOBALS['LANG']->sL($key) : '';
    }
}
