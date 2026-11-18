<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;

/**
 * Empties the trash: runs Craft's own garbage collection with «delete all trashed», as `craft gc --delete-all-trashed`
 * does. Craft knows what belongs to a deleted element (nested entries, relations, search index), so the work is left
 * to it.
 */
class ClearTrash extends Action
{
    private const PASSES = 3;

    public function id(): string
    {
        return 'clear-trash';
    }

    public function figure(): string
    {
        return 'deleted';
    }

    public function button(): string
    {
        return self::t('Clear…');
    }

    public function label(): string
    {
        return self::t('Empty the trash');
    }

    public function description(): string
    {
        return self::t('Runs Craft’s garbage collection and removes everything in the trash for good, without waiting for the period Craft normally keeps deleted items. Nothing in the trash can be restored afterwards.');
    }

    public function notes(): array
    {
        $notes = [
            self::t('The trash is where Craft puts what someone deletes in the control panel: entries, assets, users, categories and so on. They are not removed at once, so they can be brought back – in the list of entries, for example, under the status «{status}».', ['status' => Craft::t('app', 'Trashed')]),
            self::t('Every element counts on its own: an entry with ten nested entries (the blocks of a Matrix field) counts as eleven, and the saved versions and drafts that were deleted with it count too. So the number is much higher than the number of things someone deleted.'),
        ];

        $keep = (int)Craft::$app->getConfig()->getGeneral()->softDeleteDuration;
        if ($keep > 0) {
            $days = (int)round($keep / 86400);
            $overdue = $this->trashed(time() - $keep);
            $notes[] = self::t('Craft keeps deleted elements for {days, plural, one{# day} other{# days}} and removes them on its own after that – but only when its garbage collection happens to run, which is rare on a site with few visits.', ['days' => $days])
                . ' ' . ($overdue > 0
                    ? self::t('{n, plural, one{# element in the trash is} other{# elements in the trash are}} already older than that.', ['n' => $overdue])
                    : self::t('Nothing in the trash is older than that yet.'));
        } else {
            $notes[] = self::t('This site keeps deleted elements for ever («softDeleteDuration» is 0): they only go when the trash is emptied.');
        }
        return $notes;
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# deleted element} other{# deleted elements}}', ['n' => $count]);
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# deleted element was} other{# deleted elements were}} removed for good.', ['n' => $count]);
    }

    public function preview(): array
    {
        return ['count' => $this->trashed(), 'mb' => null, 'sample' => $this->breakdown()];
    }

    /**
     * What the trash holds, by kind of element, the biggest group first.
     *
     * @return string[]
     */
    private function breakdown(): array
    {
        $trashed = fn(): Query => (new Query())->from(['e' => Table::ELEMENTS])->where(['not', ['e.dateDeleted' => null]]);
        $formatter = Craft::$app->getFormatter();
        $lines = [];

        $byType = $trashed()->select(['e.type', 'n' => 'COUNT(*)'])->groupBy(['e.type'])->orderBy(['n' => SORT_DESC, 'e.type' => SORT_ASC])->all();
        foreach ($byType as $row) {
            $type = (string)$row['type'];
            $name = is_subclass_of($type, ElementInterface::class) ? $type::pluralDisplayName() : (substr(strrchr('\\' . $type, '\\'), 1) ?: $type);
            $lines[] = self::t('{type}: {n}', ['type' => $name, 'n' => $formatter->asInteger((int)$row['n'])]);
        }

        $versions = (int)$trashed()->andWhere(['or', ['not', ['e.revisionId' => null]], ['not', ['e.draftId' => null]]])->count();
        if ($versions > 0) {
            $lines[] = self::t('Of all these, {n, plural, one{# is a saved version or draft} other{# are saved versions or drafts}}.', ['n' => $versions]);
        }
        $nested = (int)$trashed()
            ->innerJoin(['en' => Table::ENTRIES], '[[en.id]] = [[e.id]]')
            ->andWhere(['e.type' => Entry::class, 'e.revisionId' => null, 'e.draftId' => null])
            ->andWhere(['not', ['en.primaryOwnerId' => null]])
            ->count();
        if ($nested > 0) {
            $lines[] = self::t('Of the entries, {n, plural, one{# is a nested entry} other{# are nested entries}} (the blocks of a Matrix field, for example).', ['n' => $nested]);
        }
        $oldest = $trashed()->min('[[e.dateDeleted]]');
        if ($oldest) {
            $lines[] = self::t('The oldest was deleted on {date}.', ['date' => $formatter->asDate(new \DateTime((string)$oldest, new \DateTimeZone('UTC')), 'short')]);
        }
        return $lines;
    }

    public function run(?callable $progress = null): array
    {
        $before = $this->trashed();
        $gc = Craft::$app->getGc();
        $deleteAllTrashed = $gc->deleteAllTrashed;
        $gc->deleteAllTrashed = true;
        try {
            // one pass removes the deleted versions of an entry, the entry itself only goes in the next one (Craft
            // keeps a deleted entry while it has versions): run until nothing more goes, a few times at most
            $left = $before;
            for ($pass = 0; $pass < self::PASSES && $left > 0; $pass++) {
                $gc->run(true);
                $now = $this->trashed();
                if ($now >= $left) {
                    break;
                }
                $left = $now;
                if ($progress) {
                    $progress($before - $left, $before);
                }
            }
        } finally {
            $gc->deleteAllTrashed = $deleteAllTrashed;
        }
        return ['count' => max(0, $before - $this->trashed()), 'problems' => []];
    }

    /** How many elements are in the trash – all of them, or only the ones deleted before a point in time. */
    private function trashed(?int $before = null): int
    {
        $query = (new Query())->from(Table::ELEMENTS)->where(['not', ['dateDeleted' => null]]);
        if ($before !== null) {
            $query->andWhere(['<', 'dateDeleted', gmdate('Y-m-d H:i:s', $before)]);
        }
        return (int)$query->count();
    }
}
