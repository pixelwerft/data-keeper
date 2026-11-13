<?php

namespace pixelwerft\datakeeper\maintenance;

use pixelwerft\datakeeper\cleanup\ChangeRecordsRule;
use pixelwerft\datakeeper\cleanup\Rule;
use pixelwerft\datakeeper\DataKeeper;

/**
 * Removes all change records Craft does not need: the same rows as the retention rule «Change records», but of any
 * age. Records of entries that have drafts (and of the drafts) stay, because Craft merges drafts with them.
 */
class ClearChangeRecords extends Action
{
    public function id(): string
    {
        return 'clear-change-records';
    }

    public function figure(): string
    {
        return 'changedFields';
    }

    public function button(): string
    {
        return self::t('Clear…');
    }

    public function label(): string
    {
        return self::t('Clear change records');
    }

    public function description(): string
    {
        return self::t('Removes the records of which field was changed when, whatever their age. Records of entries that have drafts stay, because Craft needs them to merge the drafts. Content, drafts and saved versions are not touched.');
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# row} other{# rows}}', ['n' => $count]);
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# row was} other{# rows were}} removed.', ['n' => $count]);
    }

    public function preview(): array
    {
        return ['count' => $this->rule()->preview()['count'], 'mb' => null, 'sample' => []];
    }

    public function run(?callable $progress = null): array
    {
        return ['count' => $this->rule()->run($progress), 'problems' => []];
    }

    /** The retention rule without its age limit. */
    private function rule(): Rule
    {
        return new class(DataKeeper::$plugin->getSettings()) extends ChangeRecordsRule {
            public function keepDays(): int
            {
                return 0;
            }
        };
    }
}
