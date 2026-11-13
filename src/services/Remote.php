<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Growth;
use pixelwerft\datakeeper\helpers\Levels;

/**
 * Remote queries: the stored measurement as JSON for a tool outside the site (a dashboard that watches several
 * sites, for example).
 *
 * Off until «Allow remote queries» is switched on in the settings. Access needs the key of the site: a random string
 * that is generated once, kept in the plugin's file in `storage/` (not in the project config, so it does not end up
 * in Git) and stays the same until someone resets it.
 */
class Remote extends Component
{
    /** Version of the answer's structure; raised when a field changes its meaning or goes away. */
    public const SCHEMA = 1;

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
     * What the overview shows, as plain data: the key figures with their levels and changes, the tables, the folders,
     * their sums and the course. Reads the stored measurement only – it never measures. Labels are English.
     *
     * @param bool $daily the course day by day instead of the last measurement of each month
     * @return array<string, mixed>
     */
    public function data(bool $daily = false): array
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
            'schema' => self::SCHEMA,
            'plugin' => $plugin->getVersion(),
            'site' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'url' => UrlHelper::cpUrl('data-keeper'),
            'measuredAt' => $last['measuredAt'] ?? null,
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
