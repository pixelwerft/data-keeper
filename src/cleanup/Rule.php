<?php

namespace pixelwerft\datakeeper\cleanup;

use Craft;
use pixelwerft\datakeeper\models\Settings;

/**
 * One retention rule: what is old enough to go, how much of it there is, and how to remove it.
 *
 * A rule only ever removes anything when it is switched on in the settings AND a run is explicitly applied (not a
 * dry run).
 */
abstract class Rule
{
    public function __construct(protected Settings $settings)
    {
    }

    /** Stable identifier, used in the console (`--rule=`), in logs and in the notification state. */
    abstract public function id(): string;

    /** Short name (translated). */
    abstract public function label(): string;

    /** What the rule does, in one sentence (translated). */
    abstract public function description(): string;

    abstract public function enabled(): bool;

    /** The retention period as a sentence for the privacy policy (translated). */
    abstract public function retention(): string;

    /**
     * What a run would remove right now. Deletes nothing.
     *
     * @return array{count: int, mb: ?float, oldest: ?int, sample: string[]} `oldest` is a Unix timestamp
     */
    abstract public function preview(): array;

    /**
     * Removes what {@see preview()} counts.
     *
     * @param callable(int, int): void|null $progress receives (done, total)
     * @return int how many items were removed
     */
    abstract public function run(?callable $progress = null): int;

    /** A count with its noun, e.g. «1 version», «5 versions» (translated, with plural forms). */
    abstract public function amount(int $count): string;

    protected static function t(string $message, array $params = []): string
    {
        return Craft::t('data-keeper', $message, $params);
    }

    protected function cutoff(int $days): \DateTime
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-$days days");
    }
}
