<?php

namespace pixelwerft\datakeeper\helpers;

/**
 * The course of one key figure as a chart: turns measurements (a value per day) into the coordinates of a line chart
 * and a few facts about the period. Pure logic, no Craft dependency; the template draws it as SVG.
 */
final class Chart
{
    public const WIDTH = 640;
    public const HEIGHT = 230;

    /** Space around the plot: left for the values, bottom for the dates. */
    private const LEFT = 58;
    private const RIGHT = 16;
    private const TOP = 14;
    private const BOTTOM = 30;

    /**
     * @param array<string, int|float> $series day (Y-m-d) => value, in any order
     * @param array<string, int|float> $limits level (`warn`, `alert`) => value; drawn when it lies in the range shown
     * @param array<string, string[]> $marks day (Y-m-d) => what happened on it (a cleanup, an action)
     * @param bool $whole the figure is a count: the scale only uses whole numbers
     * @return array<string, mixed>|null null without any measurement
     */
    public static function build(array $series, array $limits = [], array $marks = [], bool $whole = false): ?array
    {
        ksort($series);
        if (!$series) {
            return null;
        }
        $values = array_map('floatval', array_values($series));
        $days = array_keys($series);

        [$from, $to, $step] = self::scale(min($values), max($values), $whole);
        $x0 = self::LEFT;
        $x1 = self::WIDTH - self::RIGHT;
        $y0 = self::TOP;
        $y1 = self::HEIGHT - self::BOTTOM;

        $t0 = self::time($days[0]);
        $t1 = self::time($days[count($days) - 1]);
        $x = fn(int $time): float => $t1 === $t0 ? round(($x0 + $x1) / 2, 1) : round($x0 + ($time - $t0) / ($t1 - $t0) * ($x1 - $x0), 1);
        $y = fn(float $value): float => round($y1 - ($value - $from) / ($to - $from) * ($y1 - $y0), 1);

        $points = [];
        foreach ($days as $i => $day) {
            $points[] = ['x' => $x(self::time($day)), 'y' => $y($values[$i]), 'date' => $day, 'value' => $values[$i]];
        }

        $line = '';
        foreach ($points as $i => $point) {
            $line .= ($i === 0 ? 'M' : ' L') . $point['x'] . ' ' . $point['y'];
        }
        $last = $points[count($points) - 1];
        $area = count($points) > 1 ? "$line L{$last['x']} $y1 L{$points[0]['x']} $y1 Z" : '';

        $yTicks = [];
        for ($value = $from; $value <= $to + $step / 1000; $value += $step) {
            $yTicks[] = ['y' => $y($value), 'value' => round($value, 6)];
        }

        $xTicks = [];
        $count = min(5, count($points));
        for ($i = 0; $i < $count; $i++) {
            $time = $count === 1 ? $t0 : (int)round($t0 + ($t1 - $t0) * $i / ($count - 1));
            $xTicks[] = [
                'x' => $x($time),
                'date' => gmdate('Y-m-d', $time),
                'anchor' => $count === 1 ? 'middle' : ($i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle')),
            ];
        }

        $lines = [];
        foreach ($limits as $level => $value) {
            if ($value > 0 && $value >= $from && $value <= $to) {
                $lines[] = ['level' => (string)$level, 'value' => $value + 0, 'y' => $y((float)$value)];
            }
        }

        $marked = [];
        ksort($marks);
        foreach ($marks as $day => $texts) {
            $time = self::time((string)$day);
            if ($time >= $t0 && $time <= $t1 && $texts) {
                $marked[] = ['x' => $x($time), 'date' => (string)$day, 'texts' => array_values(array_unique($texts))];
            }
        }

        $first = $points[0];
        $highest = $lowest = $first;
        foreach ($points as $point) {
            // the later day wins a tie, so «highest» and «lowest» name the most recent one
            $highest = $point['value'] >= $highest['value'] ? $point : $highest;
            $lowest = $point['value'] <= $lowest['value'] ? $point : $lowest;
        }

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'plot' => ['x0' => $x0, 'x1' => $x1, 'y0' => $y0, 'y1' => $y1],
            'points' => $points,
            'line' => $line,
            'area' => $area,
            'yTicks' => $yTicks,
            'decimals' => $step >= 1 ? 0 : (abs($step * 10 - round($step * 10)) < 1e-9 ? 1 : 2),
            'xTicks' => $xTicks,
            'limits' => $lines,
            'marks' => $marked,
            'first' => $first,
            'last' => $last,
            'change' => round($last['value'] - $first['value'], 1),
            'percent' => $first['value'] > 0 ? round(($last['value'] - $first['value']) / $first['value'] * 100, 1) : null,
            'highest' => $highest,
            'lowest' => $lowest,
        ];
    }

    /**
     * The range of values the chart shows and the distance of its grid lines: a little air above and below the
     * measurements, on round numbers. Starts at 0 when the measurements are not far from it.
     *
     * @return array{0: float, 1: float, 2: float} from, to, step
     */
    public static function scale(float $lowest, float $highest, bool $whole = false): array
    {
        $span = $highest - $lowest;
        $air = $span > 0 ? $span * 0.15 : max(abs($highest) * 0.1, $whole ? 1 : 0.1);
        $from = $lowest - $air;
        if ($from < 0 || $lowest <= $highest * 0.4) {
            $from = 0;
        }
        $to = $highest + $air;

        $step = self::roundStep(($to - $from) / 4, $whole);
        $from = floor($from / $step + 1e-9) * $step;
        $to = ceil($to / $step - 1e-9) * $step;
        if ($to <= $from) {
            $to = $from + $step;
        }
        return [round($from, 6), round($to, 6), $step];
    }

    /** The round number (1, 2, 2.5 or 5 times a power of ten) at or above a distance. */
    private static function roundStep(float $distance, bool $whole): float
    {
        if ($distance <= 0) {
            return 1;
        }
        $power = 10 ** floor(log10($distance));
        $part = $distance / $power;
        $round = match (true) {
            $part <= 1 => 1,
            $part <= 2 => 2,
            $part <= 2.5 && !($whole && $power <= 1) => 2.5,
            $part <= 5 => 5,
            default => 10,
        };
        return $whole ? max(1, $round * $power) : $round * $power;
    }

    private static function time(string $day): int
    {
        return (int)strtotime($day . ' 00:00:00 UTC');
    }
}
