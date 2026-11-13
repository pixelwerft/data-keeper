<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;
use craft\db\Query;

/**
 * Base for actions that go through the database tables one by one. Only the site's own tables: with a table prefix
 * the ones that carry it, without one all tables of the database (views are left out).
 */
abstract class TableAction extends Action
{
    public function figure(): string
    {
        return 'database';
    }

    public function permanent(): bool
    {
        return false;
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# table} other{# tables}}', ['n' => $count]);
    }

    public function preview(): array
    {
        $tables = $this->tables();
        return ['count' => count($tables), 'mb' => null, 'sample' => []];
    }

    public function run(?callable $progress = null): array
    {
        $tables = $this->tables();
        $total = count($tables);
        $problems = [];
        foreach ($tables as $i => $table) {
            try {
                foreach ($this->handle($table) as $problem) {
                    $problems[] = "$table: $problem";
                }
            } catch (\Throwable $e) {
                // the full message can hold SQL; it goes to the Craft log, the stored result only names the table
                Craft::error("{$this->id()} failed for $table: " . $e->getMessage(), __METHOD__);
                $problems[] = "$table: " . self::t('failed – see the Craft log for details');
            }
            if ($progress) {
                $progress($i + 1, $total);
            }
        }
        return ['count' => $total, 'problems' => $problems];
    }

    /**
     * Works on one table.
     *
     * @param string $table the full table name, with the prefix
     * @return string[] what is wrong with the table (empty: fine)
     */
    abstract protected function handle(string $table): array;

    /** @return string[] full table names, with the prefix */
    protected function tables(): array
    {
        $db = Craft::$app->getDb();
        if ($db->getIsMysql()) {
            $names = (new Query())
                ->select(['name' => 'table_name'])
                ->from('information_schema.tables')
                ->where(['table_schema' => $db->createCommand('SELECT DATABASE()')->queryScalar(), 'table_type' => 'BASE TABLE'])
                ->column();
        } elseif ($db->getIsPgsql()) {
            $names = $db->createCommand(
                "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind = 'r' AND n.nspname = current_schema()"
            )->queryColumn();
        } else {
            return [];
        }

        $prefix = (string)$db->tablePrefix;
        if ($prefix !== '') {
            $names = array_filter($names, fn($name) => str_starts_with($name, $prefix));
        }
        sort($names);
        return array_values($names);
    }

    /**
     * The messages of a MySQL table maintenance statement (`OPTIMIZE TABLE`, `CHECK TABLE`) that say something is
     * wrong. Such a statement answers with rows of `Msg_type` (status, info, note, warning, error) and `Msg_text`.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string[]
     */
    protected function mysqlProblems(array $rows): array
    {
        $problems = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row);
            $type = strtolower((string)($row['msg_type'] ?? ''));
            $text = (string)($row['msg_text'] ?? '');
            if ($type === 'error' || $type === 'warning' || ($type === 'status' && strcasecmp($text, 'OK') !== 0 && stripos($text, 'up to date') === false)) {
                $problems[] = $text;
            }
        }
        return $problems;
    }
}
