<?php

namespace pixelwerft\datakeeper\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\datakeeper\helpers\Growth;

class GrowthTest extends TestCase
{
    public function testCompareUsesTheLatestEarlierDay(): void
    {
        $history = [
            '2026-10-01' => ['database' => 100.0, 'revisions' => 10],
            '2026-10-20' => ['database' => 150.0, 'revisions' => 40],
            '2026-11-04' => ['database' => 999.0, 'revisions' => 999],
        ];
        $result = Growth::compare(['database' => 160.5, 'revisions' => 55], '2026-11-04', $history);

        $this->assertSame('2026-10-20', $result['since']);
        $this->assertSame(10.5, $result['values']['database']);
        $this->assertSame(15.0, $result['values']['revisions']);
    }

    public function testCompareWithoutEarlierDayGivesNull(): void
    {
        $result = Growth::compare(['database' => 1.0], '2026-11-04', ['2026-11-04' => ['database' => 1.0]]);

        $this->assertNull($result['since']);
        $this->assertNull($result['values']['database']);
    }

    public function testByMonthKeepsTheLastDayOfEachMonthNewestFirst(): void
    {
        $history = [
            '2026-09-03' => ['database' => 1],
            '2026-09-28' => ['database' => 2],
            '2026-10-15' => ['database' => 3],
        ];
        $rows = Growth::byMonth($history);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-15', $rows[0]['date']);
        $this->assertSame('2026-09-28', $rows[1]['date']);
        $this->assertSame(2, $rows[1]['database']);
    }

    public function testByMonthLimit(): void
    {
        $history = [];
        for ($m = 1; $m <= 12; $m++) {
            $history[sprintf('2026-%02d-01', $m)] = ['database' => $m];
        }
        $this->assertCount(3, Growth::byMonth($history, 3));
    }

    public function testPruneDropsOldDays(): void
    {
        $history = ['2025-01-01' => [], '2026-10-30' => [], '2026-11-04' => []];
        $kept = Growth::prune($history, '2026-11-04', 10);

        $this->assertSame(['2026-10-30', '2026-11-04'], array_keys($kept));
    }
}
