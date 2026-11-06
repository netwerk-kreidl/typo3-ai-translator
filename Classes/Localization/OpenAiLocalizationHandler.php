<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Localization;

final class OpenAiLocalizationHandler extends AbstractAiLocalizationHandler
{
    protected function getProviderIdentifier(): string
    {
        return 'openai';
    }
}
