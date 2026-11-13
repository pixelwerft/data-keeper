<?php

namespace pixelwerft\datakeeper\helpers;

/**
 * Severity levels of a key figure or notification, and how to compare them. Pure logic, no Craft dependency.
 */
final class Levels
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARN = 'warn';
    public const ALERT = 'alert';

    private const RANK = [self::OK => 0, self::INFO => 1, self::WARN => 2, self::ALERT => 3];

    /**
     * The level of a value against its limits (`warn` and `alert`; a limit of 0 or missing means «not checked»).
     *
     * @param array{warn?: int|float, alert?: int|float}|null $limit
     */
    public static function forValue(?array $limit, int|float $value): string
    {
        $alert = (float)($limit['alert'] ?? 0);
        $warn = (float)($limit['warn'] ?? 0);

        return match (true) {
            $alert > 0 && $value >= $alert => self::ALERT,
            $warn > 0 && $value >= $warn => self::WARN,
            default => self::OK,
        };
    }

    public static function rank(string $level): int
    {
        return self::RANK[$level] ?? 0;
    }

    /** @param string[] $levels */
    public static function worst(array $levels): string
    {
        $worst = self::OK;
        foreach ($levels as $level) {
            if (self::rank($level) > self::rank($worst)) {
                $worst = $level;
            }
        }
        return $worst;
    }

    /** Whether $level is at least as severe as $minimum. */
    public static function atLeast(string $level, string $minimum): bool
    {
        return self::rank($level) >= self::rank($minimum);
    }
}
