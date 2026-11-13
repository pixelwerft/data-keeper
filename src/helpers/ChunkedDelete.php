<?php

namespace pixelwerft\datakeeper\helpers;

use Craft;
use craft\helpers\Db;

/**
 * Deletes old rows in small chunks, so a big table is not locked by one huge statement.
 */
final class ChunkedDelete
{
    /**
     * @param string $table table name with the prefix syntax, e.g. `{{%changedfields}}`
     * @param string $column a date column of that table (must be a plain identifier)
     * @param \DateTimeInterface $before rows with a value older than this are deleted
     * @return int rows deleted
     */
    public static function olderThan(string $table, string $column, \DateTimeInterface $before, int $chunk = 5000, ?callable $tick = null): int
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Invalid column name: $column");
        }
        $db = Craft::$app->getDb();
        $cutoff = Db::prepareDateForDb($before);
        $table = $db->quoteTableName($table);
        $col = $db->quoteColumnName($column);

        $sql = $db->getIsPgsql()
            ? "DELETE FROM $table WHERE ctid IN (SELECT ctid FROM $table WHERE $col < :cutoff LIMIT $chunk)"
            : "DELETE FROM $table WHERE $col < :cutoff LIMIT $chunk";

        $total = 0;
        do {
            $deleted = $db->createCommand($sql, [':cutoff' => $cutoff])->execute();
            $total += $deleted;
            if ($tick) {
                $tick($total);
            }
        } while ($deleted >= $chunk);

        return $total;
    }
}
