<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Provider;

/**
 * Ein Übersetzungsdienst (DeepL, OpenAI, Anthropic …). Neue Anbieter werden über das Interface
 * automatisch registriert (Tag „ai_translator.provider“).
 */
interface TranslationProviderInterface
{
    public function getIdentifier(): string;

    public function getLabel(): string;

    /** API-Key vorhanden? */
    public function isConfigured(): bool;

    /**
     * @throws TranslationException
     */
    public function translate(TranslationRequest $request): TranslationResult;
}
