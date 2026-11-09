<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Cost;

use Netwerk\AiTranslator\Provider\TranslationException;

/**
 * Das Monatslimit würde mit dieser Übersetzung überschritten – nichts wurde übertragen.
 */
final class BudgetExceededException extends TranslationException {}
