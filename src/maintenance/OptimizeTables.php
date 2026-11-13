<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;

/**
 * Optimizes the tables: `OPTIMIZE TABLE` on MySQL and MariaDB (InnoDB rebuilds the table and gives unused space
 * back), `VACUUM (ANALYZE)` on PostgreSQL (frees the space of removed rows for reuse and refreshes the statistics).
 * Worth it after a lot was removed. No data is changed.
 */
class OptimizeTables extends TableAction
{
    public function id(): string
    {
        return 'optimize-tables';
    }

    public function button(): string
    {
        return self::t('Optimize tables…');
    }

    public function label(): string
    {
        return self::t('Optimize tables');
    }

    public function description(): string
    {
        return Craft::$app->getDb()->getIsPgsql()
            ? self::t('Runs VACUUM (ANALYZE) on every table: the space of removed rows becomes reusable and the statistics are refreshed. No data is changed. Worth it after a lot was removed.')
            : self::t('Runs OPTIMIZE TABLE on every table: the table is rebuilt and unused space is given back. No data is changed. Worth it after a lot was removed. On a big database it takes a while and needs free disk space of about the size of the largest table.');
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# table was} other{# tables were}} optimized.', ['n' => $count]);
    }

    public function available(): bool
    {
        $db = Craft::$app->getDb();
        return $db->getIsMysql() || $db->getIsPgsql();
    }

    protected function handle(string $table): array
    {
        $db = Craft::$app->getDb();
        $name = $db->quoteTableName($table);
        if ($db->getIsPgsql()) {
            $db->createCommand("VACUUM (ANALYZE) $name")->execute();
            return [];
        }
        return $this->mysqlProblems($db->createCommand("OPTIMIZE TABLE $name")->queryAll());
    }
}
