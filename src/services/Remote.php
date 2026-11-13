<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\Update;
use craft\models\UpdateRelease;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Growth;
use pixelwerft\datakeeper\helpers\Levels;

/**
 * Remote queries: the last measurement and what the site runs on (Craft, PHP, the database, the plugins, the updates
 * that are waiting) as JSON for a tool outside the site – a dashboard that watches several sites, for example.
 *
 * Off until «Allow remote queries» is switched on in the settings. Access needs the key of the site: a random string
 * that is generated once, kept in the plugin's file in `storage/` (not in the project config, so it does not end up
 * in Git) and stays the same until someone resets it.
 */
class Remote extends Component
{
    /** Version of the answer's structure; raised when a field changes its meaning or goes away. */
    public const SCHEMA = 2;

    private const KEY_LENGTH = 48;

    public function enabled(): bool
    {
        return DataKeeper::$plugin->getSettings()->allowRemote;
    }

    /** The key of the site. Generated and stored the first time it is asked for. */
    public function key(): string
    {
        $key = $this->storedKey();
        if ($key !== '') {
            return $key;
        }
        // under the store's lock: two requests at the same moment end up with the same key
        DataKeeper::$plugin->store->update(function(array $data) use (&$key) {
            if (empty($data['remote']['key'])) {
                $data['remote'] = $this->newKey();
            }
            $key = (string)$data['remote']['key'];
            return $data;
        });
        return $key;
    }

    /** Replaces the key by a new one. The old one stops working at once. */
    public function resetKey(): string
    {
        $remote = $this->newKey();
        DataKeeper::$plugin->store->set('remote', $remote);
        return $this->storedKey();
    }

    /** When the current key was generated (null: there is none yet). */
    public function keyCreatedAt(): ?string
    {
        return DataKeeper::$plugin->store->get('remote')['createdAt'] ?? null;
    }

    /** Whether $given is the key of the site. Never generates one: without a stored key nothing matches. */
    public function matches(string $given): bool
    {
        $key = $this->storedKey();
        return $key !== '' && $given !== '' && hash_equals($key, $given);
    }

    /** The address a tool asks. */
    public function url(): string
    {
        return UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/data-keeper/remote');
    }

    /**
     * The answer to a remote query: the key figures of the last measurement, and what the site runs on (Craft, PHP,
     * the database, the plugins) with the updates that are waiting.
     *
     * The measurement is read as it was stored – nothing is measured. The updates come from Craft, which keeps what
     * it found for a day and asks its update service again after that.
     *
     * @param bool $details also everything else the overview shows (levels, tables, folders, the course), under `details`
     * @param bool $daily in the details, the course day by day instead of the last measurement of each month
     * @return array<string, mixed>
     */
    public function data(bool $details = false, bool $daily = false): array
    {
        $data = [
            'schema' => self::SCHEMA,
            'site' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'lastMeasurement' => $this->lastMeasurement(),
            'craftCms' => $this->craftCms(),
        ];
        if ($details) {
            $data['details'] = $this->details($daily);
        }
        return $data;
    }

    /**
     * The key figures of the last measurement (null: never measured). Sizes are in MB.
     *
     * @return array<string, mixed>|null
     */
    private function lastMeasurement(): ?array
    {
        $last = DataKeeper::$plugin->measurements->last();
        if ($last === null) {
            return null;
        }
        $figures = $last['figures'];
        $folder = fn(string $key): array => [
            'size' => $last['folders'][$key]['mb'] ?? $figures[$key] ?? null,
            'files' => $last['folders'][$key]['files'] ?? null,
        ];
        return [
            'date' => $last['measuredAt'],
            'timestamp' => strtotime($last['measuredAt']),
            'sizeUnit' => 'MB',
            'databaseSize' => $figures['database'] ?? null,
            'savedVersions' => $figures['revisions'] ?? null,
            'mostVersionsOfOneEntry' => $figures['maxRevisions'] ?? null,
            'changeRecords' => $figures['changedFields'] ?? null,
            'deletedElements' => $figures['deleted'] ?? null,
            'backups' => $folder('backups'),
            'logs' => $folder('logs'),
            'cache' => ['size' => $figures['cache'] ?? null, 'files' => $last['cache']['files'] ?? null],
        ];
    }

    /**
     * What the site runs on, and the updates that are waiting.
     *
     * @return array<string, mixed>
     */
    private function craftCms(): array
    {
        $db = Craft::$app->getDb();
        $plugins = [];
        $names = [];
        foreach (Craft::$app->getPlugins()->getAllPlugins() as $handle => $plugin) {
            $plugins[] = ['name' => $plugin->name, 'handle' => $handle, 'version' => $plugin->getVersion()];
            $names[$handle] = $plugin->name;
        }

        return [
            'version' => Craft::$app->getVersion(),
            'phpVersion' => PHP_VERSION,
            'databaseVersion' => $db->getDriverLabel() . ' ' . App::normalizeVersion($db->getSchema()->getServerVersion()),
            'plugins' => $plugins,
            'updatesAvailable' => $this->updates($names),
        ];
    }

    /**
     * The versions Craft and the plugins could be updated to, newest first; empty where there is nothing to update.
     * Null if Craft could not find out (its update service was not reachable, for example).
     *
     * @param array<string, string> $names plugin handle => name
     * @return array{cms: string[], plugins: object}|null `plugins` maps the name of a plugin to its versions
     */
    private function updates(array $names): ?array
    {
        try {
            $updates = Craft::$app->getUpdates()->getUpdates();
        } catch (\Throwable $e) {
            Craft::warning('Could not read the available updates: ' . $e->getMessage(), __METHOD__);
            return null;
        }

        $versions = fn(Update $update): array => array_map(fn(UpdateRelease $release) => $release->version, $update->releases);
        $plugins = [];
        foreach ($updates->plugins as $handle => $update) {
            if ($update->releases) {
                $plugins[$names[$handle] ?? $handle] = $versions($update);
            }
        }
        // an object also when it is empty: in JSON it is always `{…}`, never `[]`
        return ['cms' => $versions($updates->cms), 'plugins' => (object)$plugins];
    }

    /**
     * Everything else the overview shows, as plain data: the key figures with their levels and changes, the tables,
     * the folders, their sums, the single measurements of the last days and the course. Labels are English.
     *
     * @return array<string, mixed>
     */
    private function details(bool $daily): array
    {
        $plugin = DataKeeper::$plugin;
        $last = $plugin->measurements->last();
        $history = $plugin->measurements->history();
        $growth = $last ? $plugin->measurements->growth($last) : ['since' => null, 'values' => []];

        $figures = [];
        $levels = [];
        foreach ($plugin->measurements->limits() as $key => $limit) {
            $value = $last['figures'][$key] ?? null;
            $level = $value === null ? null : Levels::forValue($limit, $value);
            if ($level !== null) {
                $levels[] = $level;
            }
            $figures[$key] = [
                'label' => $limit['label'],
                'value' => $value,
                'unit' => $limit['unit'],
                'level' => $level,
                'warn' => $limit['warn'],
                'alert' => $limit['alert'],
                'change' => $growth['values'][$key] ?? null,
            ];
        }

        $folders = [];
        foreach ($last['folders'] ?? [] as $key => $folder) {
            $folders[] = [
                'key' => $key,
                'label' => str_replace('{name}', (string)($folder['name'] ?? ''), $folder['label']),
                'path' => $folder['path'],
                'files' => $folder['files'],
                'mb' => $folder['mb'],
                'oldest' => $folder['oldest'] ? date(DATE_ATOM, $folder['oldest']) : null,
                'newest' => $folder['newest'] ? date(DATE_ATOM, $folder['newest']) : null,
            ];
        }

        return [
            'url' => UrlHelper::cpUrl('data-keeper'),
            'comparedWith' => $growth['since'],
            'status' => $last ? Levels::worst($levels) : null,
            'figures' => $figures,
            'tables' => $last['tables'] ?? [],
            'tablesTotal' => $last['tablesTotal'] ?? null,
            'folders' => $folders,
            'foldersTotal' => [
                'files' => array_sum(array_column($folders, 'files')),
                'mb' => round(array_sum(array_column($folders, 'mb')), 1),
            ],
            'versionAge' => $last['versionAge'] ?? null,
            'config' => $last['config'] ?? null,
            'recent' => $plugin->measurements->recent(),
            'history' => $daily ? $history : Growth::byMonth($history),
        ];
    }

    private function storedKey(): string
    {
        return (string)(DataKeeper::$plugin->store->get('remote')['key'] ?? '');
    }

    /** @return array{key: string, createdAt: string} */
    private function newKey(): array
    {
        return [
            'key' => Craft::$app->getSecurity()->generateRandomString(self::KEY_LENGTH),
            'createdAt' => (new \DateTime())->format(DATE_ATOM),
        ];
    }
}
