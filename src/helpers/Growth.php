<?php

namespace pixelwerft\datakeeper\helpers;

/**
 * Change of the key figures between measurements. Pure logic, no Craft dependency.
 */
final class Growth
{
    /**
     * Change of each figure since the latest measurement of an earlier day.
     *
     * @param array<string, int|float> $figures the current figures
     * @param string $date the day (Y-m-d) of the current measurement
     * @param array<string, array<string, int|float>> $history date => figures, any order
     * @return array{since: ?string, values: array<string, ?float>}
     */
    public static function compare(array $figures, string $date, array $history): array
    {
        ksort($history);
        $before = null;
        $since = null;
        foreach ($history as $day => $row) {
            if ($day < $date) {
                $before = $row;
                $since = $day;
            }
        }

        $values = [];
        foreach ($figures as $key => $value) {
            $values[$key] = isset($before[$key]) ? round($value - $before[$key], 1) : null;
        }
        return ['since' => $since, 'values' => $values];
    }

    /**
     * The last measurement of each month, newest first.
     *
     * @param array<string, array<string, int|float>> $history date => figures
     * @return array<int, array<string, mixed>> rows with the extra key `date`
     */
    public static function byMonth(array $history, int $months = 13): array
    {
        ksort($history);
        $perMonth = [];
        foreach ($history as $day => $row) {
            $perMonth[substr($day, 0, 7)] = ['date' => $day] + $row;
        }
        return array_slice(array_reverse(array_values($perMonth)), 0, $months);
    }

    /** Drops history entries older than $days days before $today (Y-m-d). */
    public static function prune(array $history, string $today, int $days): array
    {
        $cutoff = date('Y-m-d', strtotime($today . ' -' . max(1, $days) . ' days'));
        return array_filter($history, fn($day) => $day >= $cutoff, ARRAY_FILTER_USE_KEY);
    }
}
