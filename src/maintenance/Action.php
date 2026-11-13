<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;

/**
 * Something a person starts by hand from a key figure card: clearing change records, the trash or the logs, optimizing
 * or checking the database tables.
 *
 * Unlike a retention rule an action has no setting and no schedule. It only ever runs after someone opened its page,
 * saw what it would do and confirmed it (or from the console with `--apply`).
 */
abstract class Action
{
    /** Stable identifier, used in the address, on the console and in the stored results. */
    abstract public function id(): string;

    /** The key figure (see `Measurements::FIGURES`) whose card offers the action. */
    abstract public function figure(): string;

    /** The text of the button on the card (translated). */
    abstract public function button(): string;

    /** The title of the action (translated). */
    abstract public function label(): string;

    /** What the action does and what it leaves alone (translated). */
    abstract public function description(): string;

    /** A count with its noun, e.g. «3 files» (translated, with plural forms). */
    abstract public function amount(int $count): string;

    /** The outcome as a sentence, e.g. «3 files were removed.» (translated). */
    abstract public function outcome(int $count): string;

    /**
     * What the action would work on right now. Changes nothing.
     *
     * @return array{count: int, mb: ?float, sample: string[]}
     */
    abstract public function preview(): array;

    /**
     * Does the work.
     *
     * @param callable(int, int): void|null $progress receives (done, total)
     * @return array{count: int, problems: string[]} how many items were handled, and what went wrong (untranslated
     *         text from the database, e.g. «mytable: Corrupt»)
     */
    abstract public function run(?callable $progress = null): array;

    /** Whether the action can run on this site (some need a certain database). */
    public function available(): bool
    {
        return true;
    }

    /** Whether the action removes data for good; such an action needs a ticked confirmation. */
    public function permanent(): bool
    {
        return true;
    }

    protected static function t(string $message, array $params = []): string
    {
        return Craft::t('data-keeper', $message, $params);
    }
}
