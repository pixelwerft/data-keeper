<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;

/**
 * Saved versions (revisions) of elements: which elements have the most, how many are old, and which ones a cleanup
 * selects and removes.
 *
 * A version is selected for removal when
 * - it is not among the newest `$keep` versions of its element, and
 * - it is not the very first version (only if `$keepFirst`), and
 * - it was saved more than `$olderThanDays` days ago (only if the number is above 0).
 *
 * So «keep the newest 20» is `($keep = 20)`, and «remove everything older than a year, but never the newest 3» is
 * `($keep = 3, $olderThanDays = 365)`.
 */
class Versions extends Component
{
    /**
     * The elements with the most saved versions.
     *
     * @return array<int, array{canonicalId: int, n: int, title: ?string}>
     */
    public function top(int $limit = 5): array
    {
        $rows = (new Query())
            ->select(['canonicalId', 'n' => 'COUNT(*)'])
            ->from(Table::REVISIONS)
            ->groupBy(['canonicalId'])
            ->orderBy(['n' => SORT_DESC])
            ->limit($limit)
            ->all();

        $titles = $this->titles(array_column($rows, 'canonicalId'));
        return array_map(fn($r) => [
            'canonicalId' => (int)$r['canonicalId'],
            'n' => (int)$r['n'],
            'title' => $titles[(int)$r['canonicalId']] ?? null,
        ], $rows);
    }

    /** How many saved versions an element has. */
    public function count(int $canonicalId): int
    {
        return (int)(new Query())->from(Table::REVISIONS)->where(['canonicalId' => $canonicalId])->count();
    }

    /** How many saved versions of all elements were saved more than $days days ago. */
    public function olderThan(int $days): int
    {
        return (int)(new Query())
            ->from(['r' => Table::REVISIONS])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.revisionId]] = [[r.id]]') // the element of a revision points to its row in `revisions`
            ->where(['<', 'e.dateCreated', Db::prepareDateForDb($this->cutoff($days))])
            ->count();
    }

    /** How many versions {@see clear()} would remove from one element. */
    public function excess(int $canonicalId, int $keep, bool $keepFirst = false, int $olderThanDays = 0): int
    {
        return (int)$this->selection($keep, $keepFirst, $olderThanDays, $canonicalId)->count();
    }

    /**
     * Elements that have versions to remove, most first. `n` is the number to remove.
     *
     * @return array<int, array{canonicalId: int, n: int, title: ?string}>
     */
    public function overLimit(int $keep, bool $keepFirst = false, int $olderThanDays = 0, ?int $limit = null): array
    {
        $query = $this->selection($keep, $keepFirst, $olderThanDays)
            ->select(['x.canonicalId', 'n' => 'COUNT(*)'])
            ->groupBy(['x.canonicalId'])
            ->orderBy(['n' => SORT_DESC]);
        if ($limit !== null) {
            $query->limit($limit);
        }
        $rows = $query->all();

        $titles = $this->titles(array_column($rows, 'canonicalId'));
        return array_map(fn($r) => [
            'canonicalId' => (int)$r['canonicalId'],
            'n' => (int)$r['n'],
            'title' => $titles[(int)$r['canonicalId']] ?? null,
        ], $rows);
    }

    /**
     * Permanently deletes the selected saved versions of one element. The element itself and its current content
     * stay.
     *
     * @return int versions deleted
     */
    public function clear(int $canonicalId, int $keep, bool $keepFirst = false, int $olderThanDays = 0): int
    {
        $ids = array_map('intval', $this->selection($keep, $keepFirst, $olderThanDays, $canonicalId)->select('x.elementId')->column());
        if (!$ids) {
            return 0;
        }

        $elements = Craft::$app->getElements();
        $type = $elements->getElementTypeById($canonicalId);
        if ($type === null) {
            return 0;
        }
        $siteId = (int)(new Query())
            ->select('siteId')
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $canonicalId])
            ->orderBy(['siteId' => SORT_ASC])
            ->scalar();
        if ($siteId === 0) {
            return 0;
        }

        /** @var ElementInterface[] $revisions */
        $revisions = $type::find()
            ->revisionOf($canonicalId)
            ->siteId($siteId)
            ->status(null)
            ->id($ids)
            ->all();

        $deleted = 0;
        foreach ($revisions as $revision) {
            if ($elements->deleteElement($revision, true)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * The selected versions as a query on the alias `x` (columns `elementId`, `canonicalId`, `num`, `created`). Ranks come
     * from window functions (supported by every database Craft 5 runs on).
     */
    private function selection(int $keep, bool $keepFirst, int $olderThanDays, ?int $canonicalId = null): Query
    {
        $ranked = (new Query())
            ->select([
                'elementId' => 'e.id',
                'r.canonicalId',
                'r.num',
                'created' => 'e.dateCreated',
                'rankNewest' => 'ROW_NUMBER() OVER (PARTITION BY [[r.canonicalId]] ORDER BY [[r.num]] DESC)',
                'rankOldest' => 'ROW_NUMBER() OVER (PARTITION BY [[r.canonicalId]] ORDER BY [[r.num]] ASC)',
            ])
            ->from(['r' => Table::REVISIONS])
            // the element of a revision points to its row in `revisions` (their ids are not the same)
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.revisionId]] = [[r.id]]');
        if ($canonicalId !== null) {
            $ranked->where(['r.canonicalId' => $canonicalId]);
        }

        $query = (new Query())
            ->from(['x' => $ranked])
            ->where(['>', 'x.rankNewest', max(0, $keep)]);
        if ($keepFirst) {
            $query->andWhere(['>', 'x.rankOldest', 1]);
        }
        if ($olderThanDays > 0) {
            $query->andWhere(['<', 'x.created', Db::prepareDateForDb($this->cutoff($olderThanDays))]);
        }
        return $query;
    }

    /** @return array<int, string> element id => title (only where there is one) */
    private function titles(array $canonicalIds): array
    {
        if (!$canonicalIds) {
            return [];
        }
        $rows = (new Query())
            ->select(['elementId', 'title' => 'MAX([[title]])'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $canonicalIds])
            ->groupBy(['elementId'])
            ->all();
        $titles = [];
        foreach ($rows as $row) {
            if ($row['title'] !== null && $row['title'] !== '') {
                $titles[(int)$row['elementId']] = (string)$row['title'];
            }
        }
        return $titles;
    }

    private function cutoff(int $days): \DateTime
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-$days days");
    }
}
