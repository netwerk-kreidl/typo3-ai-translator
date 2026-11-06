<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Service;

final readonly class TranslatableField
{
    public const KIND_TEXT = 'text';
    public const KIND_HTML = 'html';
    public const KIND_LINK = 'link';

    public function __construct(
        public string $name,
        public string $kind,
        public string $label,
        public int $maxLength = 0,
    ) {}
}
