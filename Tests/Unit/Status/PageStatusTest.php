<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Tests\Unit\Status;

use Netwerk\AiTranslator\Status\OutdatedRecord;
use Netwerk\AiTranslator\Status\PageStatus;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageStatusTest extends UnitTestCase
{
    #[Test]
    public function stateReflectsMissingOutdatedAndIncompleteTranslations(): void
    {
        self::assertSame(PageStatus::STATE_NOT_TRANSLATED, (new PageStatus(1, 1, false, null, [], []))->getState());
        self::assertSame(PageStatus::STATE_CURRENT, (new PageStatus(1, 1, true, null, [], []))->getState());
        self::assertSame(PageStatus::STATE_INCOMPLETE, (new PageStatus(1, 1, false, 5, [], [['table' => 'tt_content', 'uid' => 3, 'label' => 'Text']]))->getState());
        $status = new PageStatus(1, 1, false, 5, [$this->outdated()], []);
        self::assertSame(PageStatus::STATE_OUTDATED, $status->getState());
        self::assertTrue($status->needsUpdate());
    }

    #[Test]
    public function changesAreGroupedByTopRecord(): void
    {
        $status = new PageStatus(1, 1, false, 5, [$this->outdated()], [], ['tt_content' => [20 => true]]);
        self::assertSame(3, $status->getOutdatedCount());
        self::assertSame(['tt_content' => [20 => ['Antwort', '2 neue Unterelemente']]], $status->getOutdatedByTopRecord());
        self::assertSame(1, $status->getUnreviewedCount());
        self::assertTrue($status->isUnreviewed('tt_content', 20));
        self::assertFalse($status->isUnreviewed('tt_content', 21));
    }

    private function outdated(): OutdatedRecord
    {
        return new OutdatedRecord('nw_faq_items', 2, 12, 'Frage', ['answer' => 'Antwort'], ['items' => ['table' => 'nw_faq_items', 'uids' => [7, 8]]], 'tt_content', 20);
    }
}
