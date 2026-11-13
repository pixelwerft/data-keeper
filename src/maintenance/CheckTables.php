<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;

/**
 * Checks the tables for damage with `CHECK TABLE` (MySQL and MariaDB) and lists what the database reports. It changes
 * nothing and repairs nothing: `REPAIR TABLE` does not work with InnoDB, the engine Craft needs. A damaged table is
 * restored from a backup.
 */
class CheckTables extends TableAction
{
    public function id(): string
    {
        return 'check-tables';
    }

    public function button(): string
    {
        return self::t('Check tables…');
    }

    public function label(): string
    {
        return self::t('Check tables');
    }

    public function description(): string
    {
        return self::t('Runs CHECK TABLE on every table and lists what the database reports. Nothing is changed and nothing is repaired: the database cannot repair the kind of tables Craft uses, a damaged table has to be restored from a backup. On a big database it takes a while.');
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# table was} other{# tables were}} checked.', ['n' => $count]);
    }

    public function available(): bool
    {
        return Craft::$app->getDb()->getIsMysql();
    }

    protected function handle(string $table): array
    {
        $db = Craft::$app->getDb();
        return $this->mysqlProblems($db->createCommand('CHECK TABLE ' . $db->quoteTableName($table))->queryAll());
    }
}
