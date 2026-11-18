<?php

namespace pixelwerft\datakeeper\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\datakeeper\helpers\Chart;

class ChartTest extends TestCase
{
    public function testNothingWithoutMeasurements(): void
    {
        $this->assertNull(Chart::build([]));
    }

    public function testPointsRunFromLeftToRightAndHigherValuesSitHigher(): void
    {
        $chart = Chart::build(['2026-11-10' => 300, '2026-11-01' => 100, '2026-11-05' => 200]);

        $this->assertSame(['2026-11-01', '2026-11-05', '2026-11-10'], array_column($chart['points'], 'date'));
        $xs = array_column($chart['points'], 'x');
        $ys = array_column($chart['points'], 'y');
        $this->assertTrue($xs[0] < $xs[1] && $xs[1] < $xs[2]);
        $this->assertTrue($ys[0] > $ys[1] && $ys[1] > $ys[2], 'a higher value has a smaller y');
        $this->assertSame((float)$chart['plot']['x0'], $xs[0]);
        $this->assertSame((float)$chart['plot']['x1'], $xs[2]);
        foreach ($chart['points'] as $point) {
            $this->assertGreaterThanOrEqual($chart['plot']['y0'], $point['y']);
            $this->assertLessThanOrEqual($chart['plot']['y1'], $point['y']);
        }
    }

    public function testDaysAreSpacedByTimeNotByOrder(): void
    {
        $chart = Chart::build(['2026-11-01' => 1, '2026-11-02' => 2, '2026-11-11' => 3]);
        $xs = array_column($chart['points'], 'x');

        $this->assertEqualsWithDelta(($xs[2] - $xs[0]) / 10, $xs[1] - $xs[0], 0.2);
    }

    public function testFactsAboutThePeriod(): void
    {
        $chart = Chart::build(['2026-10-01' => 200, '2026-10-15' => 260, '2026-11-01' => 150]);

        $this->assertSame(-50.0, $chart['change']);
        $this->assertSame(-25.0, $chart['percent']);
        $this->assertSame('2026-10-15', $chart['highest']['date']);
        $this->assertSame('2026-11-01', $chart['lowest']['date']);
        $this->assertSame('2026-10-01', $chart['first']['date']);
        $this->assertSame('2026-11-01', $chart['last']['date']);
    }

    public function testNoPercentFromZero(): void
    {
        $chart = Chart::build(['2026-10-01' => 0, '2026-10-02' => 5]);

        $this->assertNull($chart['percent']);
        $this->assertSame(5.0, $chart['change']);
    }

    public function testOneMeasurementIsAPointWithoutALine(): void
    {
        $chart = Chart::build(['2026-11-01' => 42]);

        $this->assertCount(1, $chart['points']);
        $this->assertSame('', $chart['area']);
        $this->assertCount(1, $chart['xTicks']);
        $this->assertSame('middle', $chart['xTicks'][0]['anchor']);
    }

    public function testTheScaleHoldsAllValuesOnRoundNumbers(): void
    {
        foreach ([[2.5, 2.9, false], [606, 612, false], [0, 0, true], [3, 3, true], [11720, 25000, true], [0.2, 1460.2, false], [4, 5, true]] as [$low, $high, $whole]) {
            [$from, $to, $step] = Chart::scale($low, $high, $whole);

            $this->assertLessThanOrEqual($low, $from, "from for {$low}–{$high}");
            $this->assertGreaterThanOrEqual($high, $to, "to for {$low}–{$high}");
            $this->assertGreaterThan($from, $to);
            $this->assertGreaterThanOrEqual(0, $from);
            $this->assertEqualsWithDelta(0, fmod(($to - $from) / $step + 0.5, 1) - 0.5, 1e-6, "whole steps for {$low}–{$high}");
            $this->assertLessThanOrEqual(8, ($to - $from) / $step, "not too many lines for {$low}–{$high}");
            if ($whole) {
                $this->assertSame(0.0, fmod($step, 1), "whole step for {$low}–{$high}");
            }
        }
    }

    public function testTheScaleStartsAtZeroWhenTheValuesAreNearIt(): void
    {
        $this->assertSame(0.0, Chart::scale(10, 100)[0]);
        $this->assertGreaterThan(0, Chart::scale(606, 612)[0]);
    }

    public function testLimitsAndMarksOnlyInsideWhatIsShown(): void
    {
        $chart = Chart::build(
            ['2026-11-01' => 900, '2026-11-10' => 1100],
            ['warn' => 1000, 'alert' => 2000],
            ['2026-11-05' => ['Cleanup', 'Cleanup'], '2026-12-01' => ['Later'], '2026-10-01' => ['Earlier']],
        );

        $this->assertSame(['warn'], array_column($chart['limits'], 'level'));
        $this->assertCount(1, $chart['marks']);
        $this->assertSame('2026-11-05', $chart['marks'][0]['date']);
        $this->assertSame(['Cleanup'], $chart['marks'][0]['texts']);
        $this->assertGreaterThan($chart['points'][0]['x'], $chart['marks'][0]['x']);
        $this->assertLessThan($chart['points'][1]['x'], $chart['marks'][0]['x']);
    }
}
