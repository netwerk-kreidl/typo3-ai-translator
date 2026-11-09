<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Service;

use Netwerk\AiTranslator\Service\SegmentCodec;
use Netwerk\AiTranslator\Service\TranslatableField;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\EventDispatcher\NoopEventDispatcher;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SegmentCodecTest extends UnitTestCase
{
    private SegmentCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new SegmentCodec(new TypoLinkCodecService(new NoopEventDispatcher()));
    }

    #[Test]
    public function onlyTheLinkTitleIsTranslated(): void
    {
        $field = new TranslatableField('nw_link', TranslatableField::KIND_LINK, 'Link');
        $encoded = $this->codec->encode($field, 't3://page?uid=7 _blank - "Lage und Anreise"', 'CTA');
        self::assertSame(['' => 'Lage und Anreise'], array_map(static fn($s) => $s->text, $encoded['segments']));
        self::assertSame('t3://page?uid=7 _blank - "Location and directions"', $this->codec->decode($encoded['template'], ['' => 'Location and directions']));
    }

    #[Test]
    public function linkWithoutTitleHasNothingToTranslate(): void
    {
        $field = new TranslatableField('nw_link', TranslatableField::KIND_LINK, 'Link');
        self::assertSame([], $this->codec->encode($field, 't3://page?uid=7', 'CTA')['segments']);
    }

    #[Test]
    public function titleAndAltAttributesInRichTextAreTranslatedSeparately(): void
    {
        $field = new TranslatableField('bodytext', TranslatableField::KIND_HTML, 'Text');
        $html = '<p>Zur <a href="t3://page?uid=7" title="Unsere Lage">Lage</a> <img src="a.jpg" alt="Fassade &amp; Garten"></p>';
        $encoded = $this->codec->encode($field, $html, 'Text');
        self::assertSame('<p>Zur <a href="t3://page?uid=7" title="{{ATTR_0}}">Lage</a> <img src="a.jpg" alt="{{ATTR_1}}"></p>', $encoded['segments']['']->text);
        self::assertTrue($encoded['segments']['']->isHtml);
        self::assertSame('Unsere Lage', $encoded['segments']['attr0']->text);
        self::assertSame('Fassade & Garten', $encoded['segments']['attr1']->text);
        self::assertSame(
            '<p>To our <a href="t3://page?uid=7" title="Our location">location</a> <img src="a.jpg" alt="Facade &amp; garden"></p>',
            $this->codec->decode($encoded['template'], [
                '' => '<p>To our <a href="t3://page?uid=7" title="{{ATTR_0}}">location</a> <img src="a.jpg" alt="{{ATTR_1}}"></p>',
                'attr0' => 'Our location',
                'attr1' => 'Facade & garden',
            ])
        );
    }

    #[Test]
    public function plainTextIsShortenedToTheFieldLength(): void
    {
        $field = new TranslatableField('seo_title', TranslatableField::KIND_TEXT, 'SEO-Titel', 10);
        $encoded = $this->codec->encode($field, 'Kurz', 'Seite');
        self::assertSame(10, $encoded['segments']['']->maxLength);
        self::assertSame('Very long…', $this->codec->decode($encoded['template'], ['' => 'Very long title'], 10));
    }

    #[Test]
    public function emptyValuesAreSkipped(): void
    {
        $field = new TranslatableField('bodytext', TranslatableField::KIND_HTML, 'Text');
        self::assertSame([], $this->codec->encode($field, '<p> </p>', 'Text')['segments']);
    }
}
