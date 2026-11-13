<?php

namespace pixelwerft\datakeeper\cleanup;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use pixelwerft\datakeeper\helpers\ChunkedDelete;
use pixelwerft\datakeeper\models\Settings;

/**
 * Removes rows older than N days from a table that is not one of Craft's own – an access log, a tracking table, a
 * plugin's history. Table and date column are configured in the settings; Craft's own tables are refused.
 */
class TableRule extends Rule
{
    public function __construct(Settings $settings, private readonly array $config)
    {
        parent::__construct($settings);
    }

    public function id(): string
    {
        return 'table:' . $this->table() . '.' . $this->column();
    }

    public function label(): string
    {
        return self::t('Table {table}', ['table' => $this->table()]);
    }

    public function description(): string
    {
        return self::t('Removes rows of «{table}» whose «{column}» is older than {n} days.', [
            'table' => $this->table(),
            'column' => $this->column(),
            'n' => $this->keepDays(),
        ]);
    }

    public function retention(): string
    {
        return self::t('Records in «{table}» are removed {n} days after «{column}».', [
            'table' => $this->table(),
            'column' => $this->column(),
            'n' => $this->keepDays(),
        ]);
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# row} other{# rows}}', ['n' => $count]);
    }

    public function enabled(): bool
    {
        return (bool)($this->config['enabled'] ?? false) && static::validateConfig($this->config) === null;
    }

    public function table(): string
    {
        return (string)($this->config['table'] ?? '');
    }

    public function column(): string
    {
        return (string)($this->config['column'] ?? '');
    }

    public function keepDays(): int
    {
        return (int)($this->config['keepDays'] ?? 0);
    }

    /** The reason a rule row is not usable (null: fine), translated. Uses the live database schema. */
    public static function validateConfig(array $config): ?string
    {
        $table = (string)($config['table'] ?? '');
        $column = (string)($config['column'] ?? '');
        $days = (int)($config['keepDays'] ?? 0);

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return self::t('Table and column may only contain letters, numbers and underscores (table without the prefix).');
        }
        if (in_array(strtolower($table), self::protectedTables(), true)) {
            return self::t('«{table}» is one of Craft’s own tables – use the built-in rules for those.', ['table' => $table]);
        }
        if ($days < 7) {
            return self::t('Keep at least 7 days.');
        }
        $schema = Craft::$app->getDb()->getSchema()->getTableSchema('{{%' . $table . '}}', true);
        if ($schema === null) {
            return self::t('The table «{table}» does not exist.', ['table' => $table]);
        }
        $columnSchema = $schema->getColumn($column);
        if ($columnSchema === null) {
            return self::t('The table «{table}» has no column «{column}».', ['table' => $table, 'column' => $column]);
        }
        if (!in_array($columnSchema->type, ['date', 'datetime', 'timestamp'], true)) {
            return self::t('The column «{column}» is not a date column.', ['column' => $column]);
        }
        return null;
    }

    /** @return string[] Craft's own table names without the prefix, lower case */
    public static function protectedTables(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(Table::class))->getConstants() as $value) {
            $names[] = strtolower(preg_replace('/^\{\{%(.*)\}\}$/', '$1', (string)$value));
        }
        return $names;
    }

    public function preview(): array
    {
        if (static::validateConfig($this->config) !== null) {
            return ['count' => 0, 'mb' => null, 'oldest' => null, 'sample' => []];
        }
        $where = ['<', $this->column(), Db::prepareDateForDb($this->cutoff($this->keepDays()))];
        $table = '{{%' . $this->table() . '}}';
        $query = (new Query())->from($table)->where($where);
        $min = $query->min('[[' . $this->column() . ']]');
        return [
            'count' => (int)$query->count(),
            'mb' => null,
            'oldest' => $min ? strtotime($min . ' UTC') : null,
            'sample' => [],
        ];
    }

    public function run(?callable $progress = null): int
    {
        if (static::validateConfig($this->config) !== null) {
            return 0;
        }
        return ChunkedDelete::olderThan(
            '{{%' . $this->table() . '}}',
            $this->column(),
            $this->cutoff($this->keepDays()),
            5000,
            $progress ? fn(int $done) => $progress($done, 0) : null,
        );
    }
}
