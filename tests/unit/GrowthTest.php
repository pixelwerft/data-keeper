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

    public function testAddRecentKeepsSeveralMeasurementsOfOneDay(): void
    {
        $recent = Growth::addRecent([], '2026-11-13T09:00:00+01:00', ['database' => 683.0]);
        $recent = Growth::addRecent($recent, '2026-11-13T09:05:00+01:00', ['database' => 650.0]);
        $recent = Growth::addRecent($recent, '2026-11-13T17:30:00+01:00', ['database' => 640.0]);

        $this->assertSame(
            ['2026-11-13T09:00:00+01:00', '2026-11-13T09:05:00+01:00', '2026-11-13T17:30:00+01:00'],
            array_keys($recent),
        );
        $this->assertSame(650.0, $recent['2026-11-13T09:05:00+01:00']['database']);
    }

    public function testAddRecentDropsWhatIsOlderThanTheDaysKept(): void
    {
        $recent = [
            '2026-10-01T08:00:00+02:00' => ['database' => 1.0],
            '2026-10-20T08:00:00+02:00' => ['database' => 2.0],
        ];
        $recent = Growth::addRecent($recent, '2026-11-13T09:00:00+01:00', ['database' => 3.0], 31);

        $this->assertSame(['2026-10-20T08:00:00+02:00', '2026-11-13T09:00:00+01:00'], array_keys($recent));
    }

    public function testAddRecentKeepsTheNewestWhenThereAreTooMany(): void
    {
        $recent = [];
        foreach (['09:00', '09:01', '09:02', '09:03'] as $time) {
            $recent = Growth::addRecent($recent, "2026-11-13T$time:00+01:00", ['database' => 1.0], 31, 3);
        }

        $this->assertSame(
            ['2026-11-13T09:01:00+01:00', '2026-11-13T09:02:00+01:00', '2026-11-13T09:03:00+01:00'],
            array_keys($recent),
        );
    }

    public function testAddRecentOrdersByTimeAcrossTimeZones(): void
    {
        $recent = Growth::addRecent([], '2026-11-13T09:00:00+01:00', ['database' => 2.0]);
        // 07:30 UTC is earlier than 09:00 +01:00 (08:00 UTC), although it was added later
        $recent = Growth::addRecent($recent, '2026-11-13T07:30:00+00:00', ['database' => 1.0]);

        $this->assertSame(['2026-11-13T07:30:00+00:00', '2026-11-13T09:00:00+01:00'], array_keys($recent));
    }

    public function testRemoveRecentPutsTheLatestMeasurementLeftOfThatDayIntoTheCourse(): void
    {
        $recent = [
            '2026-11-13T09:00:00+01:00' => ['database' => 683.0],
            '2026-11-13T09:05:00+01:00' => ['database' => 650.0],
            '2026-11-13T17:30:00+01:00' => ['database' => 640.0],
        ];
        $history = ['2026-11-12' => ['database' => 700.0], '2026-11-13' => ['database' => 640.0]];

        $result = Growth::removeRecent($recent, $history, '2026-11-13T17:30:00+01:00');

        $this->assertSame(['2026-11-13T09:00:00+01:00', '2026-11-13T09:05:00+01:00'], array_keys($result['recent']));
        $this->assertSame(650.0, $result['history']['2026-11-13']['database']);
        $this->assertSame(700.0, $result['history']['2026-11-12']['database']);
    }

    public function testRemoveRecentDropsTheDayFromTheCourseWhenNothingIsLeftOfIt(): void
    {
        $recent = [
            '2026-11-12T08:00:00+01:00' => ['database' => 700.0],
            '2026-11-13T09:00:00+01:00' => ['database' => 683.0],
        ];
        $history = ['2026-11-12' => ['database' => 700.0], '2026-11-13' => ['database' => 683.0]];

        $result = Growth::removeRecent($recent, $history, '2026-11-12T08:00:00+01:00');

        $this->assertSame(['2026-11-13T09:00:00+01:00'], array_keys($result['recent']));
        $this->assertSame(['2026-11-13'], array_keys($result['history']));
    }

    public function testRemoveRecentOfAnUnknownMeasurementChangesNothing(): void
    {
        $recent = ['2026-11-13T09:00:00+01:00' => ['database' => 683.0]];

        $this->assertNull(Growth::removeRecent($recent, ['2026-11-13' => ['database' => 683.0]], '2026-11-13T10:00:00+01:00'));
    }
}
