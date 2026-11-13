<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\maintenance\Action;
use pixelwerft\datakeeper\maintenance\CheckTables;
use pixelwerft\datakeeper\maintenance\ClearChangeRecords;
use pixelwerft\datakeeper\maintenance\ClearLogs;
use pixelwerft\datakeeper\maintenance\ClearTrash;
use pixelwerft\datakeeper\maintenance\OptimizeTables;

/**
 * The actions a person starts by hand from a key figure card (clear change records, empty the trash, clear the logs,
 * optimize and check the tables), and what the last ones did.
 *
 * Nothing here runs by itself or on a schedule.
 */
class Maintenance extends Component
{
    private const KEEP_RESULTS = 30;

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

        DataKeeper::$plugin->store->update(function(array $data) use ($report) {
            $results = $data['actions'] ?? [];
            $results[] = $report;
            $data['actions'] = array_slice($results, -self::KEEP_RESULTS);
            return $data;
        });

        return $report;
    }

    /**
     * What the last actions did, newest first, ready to show (in the language of whoever reads it).
     *
     * @return array<int, array{at: string, label: string, text: string, ok: bool, problems: string[], source: string}>
     */
    public function results(int $limit = 5): array
    {
        $out = [];
        foreach (array_slice(array_reverse(DataKeeper::$plugin->store->get('actions', [])), 0, $limit) as $report) {
            $out[] = [
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
     * Deletes one file from the backup folder. Only a file {@see backupFiles()} lists can go: the name is compared
     * with that list, so nothing outside the folder (and no folder, link or hidden file) can be named.
     */
    public function deleteBackup(string $name): bool
    {
        if (!in_array($name, array_column($this->backupFiles(), 'name'), true)) {
            return false;
        }
        $deleted = @unlink(Craft::$app->getPath()->getDbBackupPath(false) . DIRECTORY_SEPARATOR . $name);
        if ($deleted) {
            Craft::info("Deleted the backup file $name.", __METHOD__);
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
        foreach ([new OptimizeTables(), new CheckTables(), new ClearChangeRecords(), new ClearTrash(), new ClearLogs()] as $action) {
            $byId[$action->id()] = $action;
        }
        return $byId;
    }
}
