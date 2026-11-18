<?php

namespace pixelwerft\datakeeper\cleanup;

use Craft;

/**
 * Removes log files that have not been written to for N days.
 */
class LogsRule extends FileRule
{
    public function id(): string
    {
        return 'logs';
    }

    public function label(): string
    {
        return self::t('Log files');
    }

    public function description(): string
    {
        $text = self::t('Removes log files that have not been written to for {n} days.', ['n' => $this->keepDays()]);
        $kept = $this->settings->logsKept();
        return $kept ? $text . ' ' . self::t('Left alone, as the settings say: {files}.', ['files' => implode(', ', $kept)]) : $text;
    }

    public function retention(): string
    {
        return self::t('Log files are removed {n} days after they were last written to.', ['n' => $this->keepDays()]);
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# file} other{# files}}', ['n' => $count]);
    }

    public function enabled(): bool
    {
        return $this->settings->logsEnabled;
    }

    public function keepDays(): int
    {
        return max(1, $this->settings->logsKeepDays);
    }

    protected function folder(): ?string
    {
        return Craft::$app->getPath()->getLogPath(false);
    }

    protected function patterns(): array
    {
        return ['*.log', '*.log.*'];
    }

    /** The files the settings want kept never become candidates – whatever a subclass selects. */
    protected function candidates(): array
    {
        return array_values(array_filter(parent::candidates(), fn($f) => !$this->settings->keepsLog(basename($f['path']))));
    }

    protected function select(array $files): array
    {
        $limit = $this->cutoff($this->keepDays())->getTimestamp();
        return array_values(array_filter($files, fn($f) => $f['mtime'] < $limit));
    }
}
