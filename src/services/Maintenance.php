<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\helpers\Queue as QueueHelper;
use craft\queue\Queue;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\jobs\MaintenanceJob;
use pixelwerft\datakeeper\maintenance\Action;
use pixelwerft\datakeeper\maintenance\CheckTables;
use pixelwerft\datakeeper\maintenance\ClearCaches;
use pixelwerft\datakeeper\maintenance\ClearChangeRecords;
use pixelwerft\datakeeper\maintenance\ClearLogs;
use pixelwerft\datakeeper\maintenance\ClearTrash;
use pixelwerft\datakeeper\maintenance\CompressBackups;
use pixelwerft\datakeeper\maintenance\OptimizeTables;

/**
 * The actions a person starts by hand from a key figure card (clear change records, empty the trash, clear the logs
 * and the caches, optimize and check the tables), and what the last ones did.
 *
 * Nothing here runs by itself or on a schedule.
 *
 * An action started in the control panel runs in the queue. Until it is through, it is remembered as «pending», so
 * the overview can say that it is waiting or running instead of showing the old numbers as if nothing had happened.
 */
class Maintenance extends Component
{
    private const KEEP_RESULTS = 30;

    /** States of an action that was started and is not through yet. */
    public const STATE_WAITING = 'waiting';
    public const STATE_RUNNING = 'running';
    public const STATE_FAILED = 'failed';

    /** The key figures a retention rule changes when it removes something (`*`: a rule for any other table). */
    private const FIGURES_OF_RULES = [
        'revisions' => ['revisions', 'maxRevisions', 'database'],
        'backups' => ['backups'],
        'logs' => ['logs'],
        'changeRecords' => ['changedFields', 'database'],
        '*' => ['database'],
    ];

    /** After this many hours an action that never reported back is no longer shown as pending. */
    private const PENDING_HOURS = 6;

    /** A job that still waits after this many seconds probably waits for a queue that nobody runs. */
    private const SLOW_AFTER = 45;

    /** How long a job may take before the queue gives it up (seconds). Rebuilding tables or zipping backups takes a while. */
    private const TTR = 3600;

    /** @return array<string, Action> the actions that can run on this site, by id */
    public function actions(): array
    {
        return array_filter($this->all(), fn(Action $action) => $action->available());
    }

    public function action(string $id): ?Action
    {
        return $this->actions()[$id] ?? null;
    }

    /** @return array<string, Action[]> the actions of each key figure, by the figure's key */
    public function byFigure(): array
    {
        $out = [];
        foreach ($this->actions() as $action) {
            $out[$action->figure()][] = $action;
        }
        return $out;
    }

    /**
     * Puts an action into the queue and remembers it as pending.
     *
     * @param int|null $confirmed what the person saw and confirmed (see {@see run()})
     */
    public function queue(string $id, ?int $confirmed, string $source = 'cp'): void
    {
        $jobId = QueueHelper::push(new MaintenanceJob(['id' => $id, 'confirmed' => $confirmed, 'source' => $source]), ttr: self::TTR);
        $this->track($id, $jobId);
    }

    /** Remembers something that was put into the queue as pending. */
    private function track(string $key, ?string $jobId): void
    {
        $entry = ['id' => $key, 'at' => (new \DateTime())->format(DATE_ATOM), 'jobId' => $jobId];
        DataKeeper::$plugin->store->update(function(array $data) use ($key, $entry) {
            $data['pending'][$key] = $entry;
            return $data;
        });
    }

    /** Stores what a run did, for the list of the last actions. */
    private function record(array $report): void
    {
        DataKeeper::$plugin->store->update(function(array $data) use ($report) {
            $results = $data['actions'] ?? [];
            $results[] = $report;
            $data['actions'] = array_slice($results, -self::KEEP_RESULTS);
            return $data;
        });
    }

    /** An action is through (or gave up): it is no longer pending. */
    public function finished(string $id): void
    {
        if (!isset(DataKeeper::$plugin->store->get('pending', [])[$id])) {
            return;
        }
        DataKeeper::$plugin->store->update(function(array $data) use ($id) {
            unset($data['pending'][$id]);
            return $data;
        });
    }

    /**
     * The actions that were started and are not through yet, oldest first, ready to show.
     *
     * @return array<int, array{id: string, label: string, at: string, state: string, slow: bool, progress: ?int}>
     *         `slow`: it has been waiting for a while – the queue may not be running; `progress` in percent
     */
    public function pending(): array
    {
        $out = [];
        foreach (DataKeeper::$plugin->store->get('pending', []) as $id => $entry) {
            $age = time() - (int)strtotime((string)($entry['at'] ?? ''));
            $state = $age > self::PENDING_HOURS * 3600 ? null : $this->stateOf($entry['jobId'] ?? null);
            if ($state === null) {
                // through without telling (the job was released by hand, for example), or too old to matter
                $this->finished((string)$id);
                continue;
            }
            $out[] = [
                'id' => (string)$id,
                'label' => $this->labelOf((string)$id),
                'at' => (string)$entry['at'],
                'state' => $state,
                'slow' => $state === self::STATE_WAITING && $age > self::SLOW_AFTER,
                'progress' => $state === self::STATE_RUNNING ? $this->progressOf($entry['jobId'] ?? null) : null,
            ];
        }
        return $out;
    }

    /** Whether an action is waiting or running right now. */
    public function isPending(string $id): bool
    {
        foreach ($this->pending() as $entry) {
            if ($entry['id'] === $id && $entry['state'] !== self::STATE_FAILED) {
                return true;
            }
        }
        return false;
    }

    /**
     * What changes when something about the pending actions changes: the overview asks for it every few seconds and
     * loads itself again when it is different from what it was built with.
     *
     * @param array<int, array{id: string, state: string}> $pending
     */
    public function stateToken(array $pending): string
    {
        return md5(implode('|', array_map(fn(array $entry) => $entry['id'] . ':' . $entry['state'], $pending)));
    }

    /**
     * The result of the action that finished last, if that was within the last minutes – what the overview shows on
     * top once it loaded itself again.
     *
     * @return array{at: string, label: string, text: string, ok: bool, problems: string[], source: string}|null
     */
    public function justFinished(int $withinSeconds = 180): ?array
    {
        $last = $this->results(1)[0] ?? null;
        return $last && time() - (int)strtotime($last['at']) <= $withinSeconds ? $last : null;
    }

    /** Where the job of a pending action stands in the queue (null: it is gone). */
    private function stateOf(?string $jobId): ?string
    {
        $queue = Craft::$app->getQueue();
        if ($jobId === null || !$queue instanceof Queue) {
            // a queue that cannot be asked: taken as waiting until the action reports back
            return self::STATE_WAITING;
        }
        try {
            return match ($queue->status($jobId)) {
                Queue::STATUS_WAITING => self::STATE_WAITING,
                Queue::STATUS_RESERVED => self::STATE_RUNNING,
                Queue::STATUS_FAILED => self::STATE_FAILED,
                default => null,
            };
        } catch (\Throwable) {
            return self::STATE_WAITING;
        }
    }

    private function progressOf(?string $jobId): ?int
    {
        $queue = Craft::$app->getQueue();
        if ($jobId === null || !$queue instanceof Queue) {
            return null;
        }
        try {
            $progress = $queue->getJobDetails($jobId)['progress'] ?? null;
            return is_numeric($progress) ? (int)$progress : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Runs an action and stores what it did.
     *
     * @param int|null $confirmed what a person saw and confirmed: an action that removes data and would now remove
     *        more than that is skipped (status `changed`), so it never removes more than was shown
     * @param string $source who runs it: `cp`, `console`
     * @param callable(int, int): void|null $progress receives (done, total)
     * @return array{at: string, id: string, source: string, status: string, count: int, problems: string[]}
     *         `status` is `done`, `changed` or `failed`
     */
    public function run(string $id, ?int $confirmed = null, string $source = 'console', ?callable $progress = null): array
    {
        $action = $this->action($id);
        if ($action === null) {
            throw new \InvalidArgumentException("Unknown action: $id");
        }

        $report = [
            'at' => (new \DateTime())->format(DATE_ATOM),
            'id' => $id,
            'source' => $source,
            'status' => 'done',
            'count' => 0,
            'problems' => [],
        ];

        if ($action->permanent() && $confirmed !== null && $action->preview()['count'] > $confirmed) {
            $report['status'] = 'changed';
        } else {
            try {
                $result = $action->run($progress);
                $report['count'] = $result['count'];
                $report['problems'] = array_slice($result['problems'], 0, 20);
            } catch (\Throwable $e) {
                // the raw text can hold SQL or file paths: it goes to the Craft log, not into what is stored and shown
                Craft::error("Action $id failed: " . $e->getMessage(), __METHOD__);
                $report['status'] = 'failed';
            }
        }

        $this->record($report);

        return $report;
    }

    /**
     * What the last actions did, newest first, ready to show (in the language of whoever reads it).
     *
     * @return array<int, array{key: string, at: string, label: string, text: string, ok: bool, problems: string[], source: string}>
     *         `key` names the entry for {@see forget()}
     */
    public function results(int $limit = 5): array
    {
        $out = [];
        foreach (array_slice(array_reverse(DataKeeper::$plugin->store->get('actions', [])), 0, $limit) as $report) {
            $out[] = [
                'key' => self::keyOf($report),
                'at' => $report['at'],
                'label' => $this->labelOf($report['id']),
                'text' => $this->describe($report),
                'ok' => $report['status'] === 'done' && !$report['problems'],
                'problems' => $report['problems'],
                'source' => $report['source'],
            ];
        }
        return $out;
    }

    /**
     * Takes entries out of the list of the last actions. Only the entries go: what an action did stays done.
     *
     * @param string[] $keys as {@see results()} gives them
     * @return int how many entries were removed
     */
    public function forget(array $keys): int
    {
        $removed = 0;
        $stored = DataKeeper::$plugin->store->update(function(array $data) use ($keys, &$removed) {
            $all = $data['actions'] ?? [];
            $kept = array_values(array_filter($all, fn(array $report) => !in_array(self::keyOf($report), $keys, true)));
            $removed = count($all) - count($kept);
            $data['actions'] = $kept;
            return $data;
        });
        return $stored ? $removed : 0;
    }

    /** What names one stored result: when it was and what it was. */
    private static function keyOf(array $report): string
    {
        return ($report['at'] ?? '') . '|' . ($report['id'] ?? '');
    }

    /**
     * What was done on which day, for the charts: an action or a cleanup that removed something is marked on the
     * course of the key figures it changes.
     *
     * @return array<string, array<string, string[]>> key figure => day (Y-m-d) => what happened (translated)
     */
    public function marks(): array
    {
        $marks = [];
        $all = $this->all();
        foreach (DataKeeper::$plugin->store->get('actions', []) as $report) {
            $action = $all[$report['id'] ?? ''] ?? null;
            // a check changes nothing, so there is nothing to see in a course
            if ($action === null || $action instanceof CheckTables || ($report['status'] ?? '') !== 'done') {
                continue;
            }
            $day = substr((string)$report['at'], 0, 10);
            $marks[$action->figure()][$day][] = $action->label();
            if (in_array($action->figure(), ['changedFields', 'deleted'], true)) {
                // what is removed from the database also shows in its size
                $marks['database'][$day][] = $action->label();
            }
        }

        $cleanup = Craft::t('data-keeper', 'Cleanup');
        foreach (DataKeeper::$plugin->store->get('runs', []) as $run) {
            $day = substr((string)($run['at'] ?? ''), 0, 10);
            foreach ($run['results'] ?? [] as $rule => $result) {
                if (($result['removed'] ?? 0) <= 0) {
                    continue;
                }
                foreach (self::FIGURES_OF_RULES[$rule] ?? self::FIGURES_OF_RULES['*'] as $figure) {
                    $marks[$figure][$day][] = $cleanup;
                }
            }
        }
        return $marks;
    }

    /**
     * The regular files that lie directly in the backup folder, newest first.
     *
     * @return array<int, array{name: string, mtime: int, mb: float}>
     */
    public function backupFiles(): array
    {
        $folder = Craft::$app->getPath()->getDbBackupPath(false);
        if (!$folder || !is_dir($folder)) {
            return [];
        }
        $files = [];
        foreach (scandir($folder) ?: [] as $name) {
            $path = $folder . DIRECTORY_SEPARATOR . $name;
            if ($name[0] === '.' || is_link($path) || !is_file($path)) {
                continue;
            }
            $files[] = ['name' => $name, 'mtime' => (int)filemtime($path), 'mb' => round((int)filesize($path) / 1048576, 1)];
        }
        usort($files, fn($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
        return $files;
    }

    /**
     * Deletes files from the backup folder. Only files {@see backupFiles()} lists can go: every name is compared
     * with that list, so nothing outside the folder (and no folder, link or hidden file) can be named.
     *
     * @param string[] $names
     * @return int how many files were deleted
     */
    public function deleteBackups(array $names): int
    {
        $listed = array_column($this->backupFiles(), 'name');
        $folder = Craft::$app->getPath()->getDbBackupPath(false);
        $deleted = 0;
        foreach (array_unique($names) as $name) {
            if (!in_array($name, $listed, true)) {
                continue;
            }
            if (@unlink($folder . DIRECTORY_SEPARATOR . $name)) {
                $deleted++;
                Craft::info("Deleted the backup file $name.", __METHOD__);
            }
        }
        if ($deleted > 0) {
            DataKeeper::$plugin->measurements->refreshFolder('backups');
        }
        return $deleted;
    }

    /** The outcome of a run as a sentence (translated). */
    public function describe(array $report): string
    {
        $action = $this->all()[$report['id']] ?? null;
        return match (true) {
            $report['status'] === 'failed' => Craft::t('data-keeper', 'failed – see the Craft log for details'),
            $report['status'] === 'changed' => Craft::t('data-keeper', 'skipped, the numbers changed'),
            $action === null => (string)$report['count'],
            $action instanceof CheckTables && !$report['problems'] => $action->outcome($report['count']) . ' ' . Craft::t('data-keeper', 'No problems found.'),
            default => $action->outcome($report['count']),
        };
    }

    private function labelOf(string $id): string
    {
        $action = $this->all()[$id] ?? null;
        return $action ? $action->label() : $id;
    }

    /** @return array<string, Action> every action by id, also the ones that cannot run on this database */
    private function all(): array
    {
        $byId = [];
        foreach ([new OptimizeTables(), new CheckTables(), new ClearChangeRecords(), new ClearTrash(), new CompressBackups(), new ClearLogs(), new ClearCaches()] as $action) {
            $byId[$action->id()] = $action;
        }
        return $byId;
    }
}
