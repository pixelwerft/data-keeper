<?php

namespace pixelwerft\datakeeper\cleanup;

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;

/**
 * Removes old change records. Craft keeps «which field changed when» in two tables to merge changes between drafts
 * and the live version. Records of elements that have no drafts are not needed for that, and they pile up: Craft's own
 * garbage collection removes them too, but only now and then. This rule does the same on a schedule and with an age.
 *
 * Records of an element that has drafts (or of a draft itself) are never touched – Craft still needs them.
 */
class ChangeRecordsRule extends Rule
{
    private const TABLES = [Table::CHANGEDFIELDS, Table::CHANGEDATTRIBUTES];

    public function id(): string
    {
        return 'change-records';
    }

    public function label(): string
    {
        return self::t('Change records');
    }

    public function description(): string
    {
        return self::t('Removes the records of which field was changed when, once they are older than {n} days. Only for entries without drafts; content is not touched.', ['n' => $this->keepDays()]);
    }

    public function retention(): string
    {
        return self::t('Internal change records (which field was changed when) are removed after {n} days.', ['n' => $this->keepDays()]);
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# row} other{# rows}}', ['n' => $count]);
    }

    public function enabled(): bool
    {
        return $this->settings->changeRecordsEnabled;
    }

    public function keepDays(): int
    {
        return max(30, $this->settings->changeRecordsKeepDays);
    }

    /**
     * The rows that are old enough and belong to a canonical element without drafts – the same selection as Craft's
     * own garbage collection (`Gc::_deletePointlessChangeData()`), plus the age.
     */
    private function candidates(string $table): Query
    {
        return (new Query())
            ->from(['t' => $table])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[t.elementId]]')
            ->leftJoin(['d' => Table::ELEMENTS], [
                'and',
                ['not', ['d.draftId' => null]],
                '[[d.canonicalId]] = [[e.id]]',
            ])
            ->where(['e.canonicalId' => null])
            ->andWhere(['d.id' => null])
            ->andWhere(['<', 't.dateUpdated', Db::prepareDateForDb($this->cutoff($this->keepDays()))]);
    }

    public function preview(): array
    {
        $count = 0;
        $oldest = null;
        foreach (self::TABLES as $table) {
            $count += (int)$this->candidates($table)->count();
            $min = $this->candidates($table)->min('[[t.dateUpdated]]');
            if ($min) {
                $time = strtotime($min . ' UTC');
                $oldest = $oldest === null ? $time : min($oldest, $time);
            }
        }
        return ['count' => $count, 'mb' => null, 'oldest' => $oldest, 'sample' => []];
    }

    public function run(?callable $progress = null): int
    {
        $cutoff = Db::prepareDateForDb($this->cutoff($this->keepDays()));
        $removed = 0;
        foreach (self::TABLES as $i => $table) {
            $elementIds = $this->candidates($table)->select('t.elementId')->distinct()->column();
            foreach (array_chunk($elementIds, 500) as $chunk) {
                // the date is checked again: a row changed since the selection is not removed
                $removed += Db::delete($table, ['and', ['elementId' => $chunk], ['<', 'dateUpdated', $cutoff]]);
            }
            if ($progress) {
                $progress($i + 1, count(self::TABLES));
            }
        }
        return $removed;
    }
}
