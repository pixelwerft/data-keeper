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
 * caches, temporary files and local file volumes – and keeps the last measurement and one set of key figures per day.
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
        'cache' => ['icon' => 'cache', 'label' => 'Cache', 'unit' => 'MB', 'warn' => 500, 'alert' => 2000],
    ];

    /** For how many days every single measurement is kept (older days keep the last one, in the course). */
    public const RECENT_DAYS = 31;

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
     * key figures also go into the list of single measurements and into the course, which has one entry per day.
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
        $cache = $this->cacheSize();
        $figures = [
            'database' => round(array_sum(array_column($tables, 'mb')), 1),
            'revisions' => $count(Table::REVISIONS),
            'maxRevisions' => $topRevisions[0]['n'] ?? 0,
            'changedFields' => $count(Table::CHANGEDFIELDS) + $count(Table::CHANGEDATTRIBUTES),
            'deleted' => $count(Table::ELEMENTS, ['not', ['dateDeleted' => null]]),
            'backups' => $folders['backups']['mb'] ?? 0,
            'logs' => $folders['logs']['mb'] ?? 0,
            'cache' => $cache['mb'],
        ];

        $general = Craft::$app->getConfig()->getGeneral();
        $now = DateTimeHelper::now();
        $result = [
            'figures' => $figures,
            'tables' => array_slice($tables, 0, 15),
            'tablesTotal' => [
                'count' => count($tables),
                'rows' => array_sum(array_column($tables, 'rows')),
                'mb' => round(array_sum(array_column($tables, 'mb')), 1),
            ],
            'folders' => $folders,
            'cache' => $cache,
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
            // every measurement on its own, also several on one day; the first time, the list starts with the
            // measurement before this one, so there is something to compare with at once
            $recent = $data['recent'] ?? [];
            if (!$recent && isset($data['last']['measuredAt'], $data['last']['date'])) {
                $recent[$data['last']['measuredAt']] = $history[$data['last']['date']] ?? $data['last']['figures'];
            }
            $data['recent'] = Growth::addRecent($recent, $result['measuredAt'], $dailyFigures, self::RECENT_DAYS);
            // the course: one entry per day, a later measurement of the same day takes the place of the earlier one
            $history[$result['date']] = $dailyFigures;
            ksort($history);
            $data['history'] = Growth::prune($history, $result['date'], $keepDays);
            $data['last'] = $result;
            return $data;
        });

        $plugin->notifications->refresh($result);
        return $result;
    }

    /**
     * Brings what the stored measurement says about saved versions up to date (their number, the most of one entry,
     * the entries with the most, how many are old) – cheap queries, no files are counted. Called after versions were
     * removed, so the overview does not go on showing the numbers from before. Does nothing before the first
     * measurement.
     */
    public function refreshVersions(): void
    {
        $plugin = DataKeeper::$plugin;
        if ($this->last() === null) {
            return;
        }
        $top = $plugin->versions->top(5);
        $figures = [
            'revisions' => (int)(new Query())->from(Table::REVISIONS)->count(),
            'maxRevisions' => $top[0]['n'] ?? 0,
        ];
        $versionAge = ['year1' => $plugin->versions->olderThan(365), 'year2' => $plugin->versions->olderThan(730)];
        $this->patch(function(array $last) use ($figures, $top, $versionAge) {
            $last['figures'] = array_merge($last['figures'], $figures);
            $last['topRevisions'] = $top;
            $last['versionAge'] = $versionAge;
            return $last;
        }, $figures);
    }

    /**
     * Counts one folder again (`backups`, `logs`, …) and puts it into the stored measurement. Called after files were
     * removed from it. Does nothing before the first measurement or for a folder the measurement does not have.
     */
    public function refreshFolder(string $key): void
    {
        $last = $this->last();
        $folder = $this->folders()[$key] ?? null;
        if ($last === null || $folder === null || !isset($last['folders'][$key])) {
            return;
        }
        $figures = isset($last['figures'][$key]) ? [$key => $folder['mb']] : [];
        $this->patch(function(array $last) use ($key, $folder, $figures) {
            $last['folders'][$key] = $folder;
            $last['figures'] = array_merge($last['figures'], $figures);
            return $last;
        }, $figures);
    }

    /**
     * Changes the stored measurement and, for the day it was taken, the key figures in the course.
     *
     * @param callable(array): array $change receives the last measurement, returns the changed one
     * @param array<string, int|float> $figures the key figures that changed
     */
    private function patch(callable $change, array $figures): void
    {
        DataKeeper::$plugin->store->update(function(array $data) use ($change, $figures) {
            if (!isset($data['last'])) {
                return $data;
            }
            $data['last'] = $change($data['last']);
            $date = $data['last']['date'] ?? null;
            if ($date !== null && isset($data['history'][$date])) {
                $data['history'][$date] = array_merge($data['history'][$date], $figures);
            }
            $at = $data['last']['measuredAt'] ?? null;
            if ($at !== null && isset($data['recent'][$at])) {
                $data['recent'][$at] = array_merge($data['recent'][$at], $figures);
            }
            return $data;
        });
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

    /**
     * The single measurements of the last days, newest first – also several of one day.
     *
     * @return array<int, array<string, mixed>> rows with the extra key `at` (ISO 8601)
     */
    public function recent(?int $limit = null): array
    {
        $recent = DataKeeper::$plugin->store->get('recent', []);
        uksort($recent, fn($a, $b) => strtotime($b) <=> strtotime($a));
        $rows = [];
        foreach ($recent as $at => $figures) {
            $rows[] = ['at' => $at] + $figures;
        }
        return $limit === null ? $rows : array_slice($rows, 0, $limit);
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

    /**
     * How much the caches on disk hold: Craft's data cache (when it is kept in files), the compiled templates and the
     * compiled classes. They are part of the «Temporary files» folder too; this is their share of it.
     *
     * @return array{mb: float, files: int}
     */
    public function cacheSize(): array
    {
        $path = Craft::$app->getPath();
        $mb = 0;
        $files = 0;
        foreach ([$path->getCachePath(false), $path->getCompiledTemplatesPath(false), $path->getCompiledClassesPath(false)] as $folder) {
            $size = $this->size($folder);
            $mb += $size['mb'];
            $files += $size['files'];
        }
        return ['mb' => round($mb, 1), 'files' => $files];
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
