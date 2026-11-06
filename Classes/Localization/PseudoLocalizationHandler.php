<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Localization;

final class PseudoLocalizationHandler extends AbstractAiLocalizationHandler
{
    protected function getProviderIdentifier(): string
    {
        return 'pseudo';
    }
}
