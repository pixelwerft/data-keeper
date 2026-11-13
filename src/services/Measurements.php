<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\fs\Local;
use craft\helpers\DateTimeHelper;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Growth;
use pixelwerft\datakeeper\helpers\Levels;

/**
 * Measures how much the site stores – database, saved versions, change records, deleted elements, backups, logs,
 * temporary files and local file volumes – and keeps the last measurement and one set of key figures per day.
 *
 * Measuring is never automatic: it runs when someone clicks «Measure now», or from the console command or the queue
 * job (for example daily from a cron job).
 */
class Measurements extends Component
{
    /**
     * From these values on a figure is at the level «warn» or «alert». Labels are translated where shown; `icon` is the
     * name of a file in `src/icons`.
     */
    public const FIGURES = [
        'database' => ['icon' => 'database', 'label' => 'Database', 'unit' => 'MB', 'warn' => 1000, 'alert' => 2000],
        'revisions' => ['icon' => 'saved-versions', 'label' => 'Saved versions', 'unit' => '', 'warn' => 25000, 'alert' => 50000],
        'maxRevisions' => ['icon' => 'versions-per-entry', 'label' => 'Most versions of one entry', 'unit' => '', 'warn' => 50, 'alert' => 100],
        'changedFields' => ['icon' => 'changelog', 'label' => 'Change records (rows)', 'unit' => '', 'warn' => 500000, 'alert' => 1000000],
        'deleted' => ['icon' => 'trash', 'label' => 'Deleted elements (in the trash)', 'unit' => '', 'warn' => 2000, 'alert' => 10000],
        'backups' => ['icon' => 'backups', 'label' => 'Backups', 'unit' => 'MB', 'warn' => 2000, 'alert' => 5000],
        'logs' => ['icon' => 'logs', 'label' => 'Logs', 'unit' => 'MB', 'warn' => 300, 'alert' => 1000],
    ];

    /**
     * The key figures with their limits (defaults overridden by the settings).
     *
     * @return array<string, array{icon: string, label: string, unit: string, warn: int|float, alert: int|float}>
     */
    public function limits(): array
    {
        $settings = DataKeeper::$plugin->getSettings();
        $limits = [];
        foreach (self::FIGURES as $key => $figure) {
            $limits[$key] = array_merge($figure, $settings->limitFor($key, $figure));
        }
        return $limits;
    }

    /** 'ok', 'warn' or 'alert' for a figure. */
    public function level(string $key, int|float $value): string
    {
        return Levels::forValue($this->limits()[$key] ?? null, $value);
    }

    /**
     * Measures everything (counting files takes a moment on big sites) and stores it as the last measurement. The
     * key figures also go into the history, one entry per day.
     *
     * @return array<string, mixed>
     */
    public function measure(): array
    {
        $plugin = DataKeeper::$plugin;
        $tables = $this->tables();
        $folders = $this->folders();
        $db = Craft::$app->getDb();
        $count = fn(string $table, array $where = []) => $db->tableExists($table)
            ? (int)(new Query())->from($table)->where($where)->count()
            : 0;

        $topRevisions = $plugin->versions->top(5);
        $figures = [
            'database' => round(array_sum(array_column($tables, 'mb')), 1),
            'revisions' => $count(Table::REVISIONS),
            'maxRevisions' => $topRevisions[0]['n'] ?? 0,
            'changedFields' => $count(Table::CHANGEDFIELDS) + $count(Table::CHANGEDATTRIBUTES),
            'deleted' => $count(Table::ELEMENTS, ['not', ['dateDeleted' => null]]),
            'backups' => $folders['backups']['mb'] ?? 0,
            'logs' => $folders['logs']['mb'] ?? 0,
        ];

        $general = Craft::$app->getConfig()->getGeneral();
        $now = DateTimeHelper::now();
        $result = [
            'figures' => $figures,
            'tables' => array_slice($tables, 0, 15),
            'folders' => $folders,
            'topRevisions' => $topRevisions,
            'versionAge' => ['year1' => $plugin->versions->olderThan(365), 'year2' => $plugin->versions->olderThan(730)],
            'backlog' => $plugin->cleanup->backlog(),
            'config' => [
                'maxRevisions' => $general->maxRevisions,
                'softDeleteDuration' => $general->softDeleteDuration,
                'backupOnUpdate' => $general->backupOnUpdate,
                'maxBackups' => $general->maxBackups,
            ],
            'measuredAt' => $now->format(DATE_ATOM),
            'date' => $now->format('Y-m-d'),
        ];

        $dailyFigures = $figures + [
            'runtime' => $folders['runtime']['mb'] ?? 0,
            'transforms' => $folders['transforms']['mb'] ?? 0,
            'files' => round(array_sum(array_map(
                fn($key) => str_starts_with($key, 'volume-') ? $folders[$key]['mb'] : 0,
                array_keys($folders),
            )), 1),
        ];
        $keepDays = $plugin->getSettings()->historyDays;
        $plugin->store->update(function(array $data) use ($result, $dailyFigures, $keepDays) {
            $history = $data['history'] ?? [];
            $history[$result['date']] = $dailyFigures;
            ksort($history);
            $data['history'] = Growth::prune($history, $result['date'], $keepDays);
            $data['last'] = $result;
            return $data;
        });

        $plugin->notifications->refresh($result);
        return $result;
    }

    /** The last measurement (null: never measured). */
    public function last(): ?array
    {
        return DataKeeper::$plugin->store->get('last');
    }

    /** @return array<string, array> date => key figures, oldest first */
    public function history(): array
    {
        $history = DataKeeper::$plugin->store->get('history', []);
        ksort($history);
        return $history;
    }

    /** @return array{since: ?string, values: array<string, ?float>} */
    public function growth(array $last): array
    {
        return Growth::compare($last['figures'], $last['date'], $this->history());
    }

    /**
     * The database tables, largest first (MySQL, MariaDB and PostgreSQL).
     *
     * @return array<int, array{name: string, rows: int, mb: float}>
     */
    public function tables(): array
    {
        $db = Craft::$app->getDb();
        if ($db->getIsMysql()) {
            $rows = (new Query())
                ->select(['name' => 'table_name', 'rows' => 'table_rows', 'bytes' => '(data_length + index_length)'])
                ->from('information_schema.tables')
                ->where(['table_schema' => $db->createCommand('SELECT DATABASE()')->queryScalar()])
                ->orderBy(['bytes' => SORT_DESC])
                ->all();
        } elseif ($db->getIsPgsql()) {
            $rows = $db->createCommand(
                "SELECT c.relname AS name, c.reltuples::bigint AS rows, pg_total_relation_size(c.oid) AS bytes
                 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind = 'r' AND n.nspname = current_schema()
                 ORDER BY bytes DESC"
            )->queryAll();
        } else {
            return [];
        }

        $prefix = $db->tablePrefix;
        return array_map(fn($r) => [
            'name' => $prefix && str_starts_with($r['name'], $prefix) ? substr($r['name'], strlen($prefix)) : $r['name'],
            'rows' => max(0, (int)$r['rows']),
            'mb' => round((int)$r['bytes'] / 1048576, 1),
        ], $rows);
    }

    /**
     * What Craft's own tables hold, and the notes from the settings for others.
     */
    public function tableNote(string $name): string
    {
        $notes = [
            'elements_sites' => 'Content of all entries – including every saved version',
            'searchindex' => 'Search index (texts for the search)',
            'changedfields' => 'Change records: which field was changed when',
            'changedattributes' => 'Change records: title, status, date',
            'relations' => 'Relations (categories, images, authors …)',
            'elements' => 'All elements (entries, users, files, versions)',
            'revisions' => 'Saved versions (list)',
            'queue' => 'Queue for background jobs',
            'sessions' => 'Signed-in sessions',
            'content' => 'Field content',
            'contentblocks' => 'Content of nested fields',
            'entries' => 'Entries',
            'assets' => 'Files in the asset volumes',
            'drafts' => 'Drafts',
            'announcements' => 'Control panel announcements',
        ];
        $own = DataKeeper::$plugin->getSettings()->tableNotes;
        if (isset($own[$name])) {
            return (string)$own[$name];
        }
        return isset($notes[$name]) ? Craft::t('data-keeper', $notes[$name]) : '';
    }

    /**
     * Backups, logs, temporary files, image transforms and every local file volume.
     *
     * @return array<string, array{label: string, name: ?string, path: string, mb: float, files: int, oldest: ?int, newest: ?int}>
     */
    public function folders(): array
    {
        $path = Craft::$app->getPath();
        $paths = [
            'backups' => ['Backups', null, $path->getDbBackupPath(false)],
            'logs' => ['Logs', null, $path->getLogPath(false)],
            'runtime' => ['Temporary files', null, $path->getRuntimePath()],
            'transforms' => ['Image transforms', null, Craft::getAlias('@webroot/assets', false) ?: null],
        ];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $fs = $volume->getFs();
            if ($fs instanceof Local) {
                $paths['volume-' . $volume->handle] = ['Files: {name}', $volume->name, $fs->getRootPath()];
            }
        }

        $folders = [];
        foreach ($paths as $key => [$label, $name, $folder]) {
            $folders[$key] = ['label' => $label, 'name' => $name, 'path' => $this->relative($folder)] + $this->size($folder);
        }
        return $folders;
    }

    /** @return array{mb: float, files: int, oldest: ?int, newest: ?int} timestamps as Unix time */
    private function size(?string $path): array
    {
        $bytes = 0;
        $files = 0;
        $oldest = $newest = null;
        if ($path && is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $bytes += $file->getSize();
                $files++;
                $mtime = $file->getMTime();
                $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
                $newest = $newest === null ? $mtime : max($newest, $mtime);
            }
        }
        return ['mb' => round($bytes / 1048576, 1), 'files' => $files, 'oldest' => $oldest, 'newest' => $newest];
    }

    private function relative(?string $path): string
    {
        $base = rtrim((string)Craft::getAlias('@root'), '/') . '/';
        return $path ? (str_starts_with($path, $base) ? substr($path, strlen($base)) : $path) : '–';
    }
}
