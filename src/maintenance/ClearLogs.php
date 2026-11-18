<?php

namespace pixelwerft\datakeeper\maintenance;

use pixelwerft\datakeeper\cleanup\LogsRule;
use pixelwerft\datakeeper\cleanup\Rule;
use pixelwerft\datakeeper\DataKeeper;

/**
 * Removes all log files in the log folder: the same files as the retention rule «Log files», but of any age.
 */
class ClearLogs extends Action
{
    public function id(): string
    {
        return 'clear-logs';
    }

    public function figure(): string
    {
        return 'logs';
    }

    public function button(): string
    {
        return self::t('Clear…');
    }

    public function label(): string
    {
        return self::t('Clear log files');
    }

    public function description(): string
    {
        return self::t('Removes the log files that lie directly in the log folder, also the ones of today. Craft starts new ones with the next entry. What the logs said is gone, so keep them if you are still looking into a problem.');
    }

    public function notes(): array
    {
        $kept = DataKeeper::$plugin->getSettings()->logsKept();
        return [$kept
            ? self::t('Left alone, as the settings say: {files}.', ['files' => implode(', ', $kept)])
            : self::t('To keep certain logs – the log of a plugin you want as a record, for example – name them in the settings under «Log files to keep». Craft’s own logs (web, console, queue, PHP errors) only help to look into a problem; no statistics depend on them.'),
        ];
    }

    public function available(): bool
    {
        return DataKeeper::$plugin->getSettings()->logsClearable;
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# file} other{# files}}', ['n' => $count]);
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# file was} other{# files were}} removed.', ['n' => $count]);
    }

    public function preview(): array
    {
        $preview = $this->rule()->preview();
        return ['count' => $preview['count'], 'mb' => $preview['mb'], 'sample' => $preview['sample']];
    }

    public function run(?callable $progress = null): array
    {
        return ['count' => $this->rule()->run($progress), 'problems' => []];
    }

    /** The retention rule without its age limit. */
    private function rule(): Rule
    {
        return new class(DataKeeper::$plugin->getSettings()) extends LogsRule {
            protected function select(array $files): array
            {
                return $files;
            }
        };
    }
}
