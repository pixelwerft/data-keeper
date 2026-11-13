<?php

namespace pixelwerft\datakeeper\cleanup;

use Craft;

/**
 * Keeps the newest N database backups in `storage/backups`, removes the older ones.
 */
class BackupsRule extends FileRule
{
    public function id(): string
    {
        return 'backups';
    }

    public function label(): string
    {
        return self::t('Database backups');
    }

    public function description(): string
    {
        return self::t('Keeps the newest {n} database backups in the backup folder and removes the older ones.', ['n' => $this->keep()]);
    }

    public function retention(): string
    {
        return self::t('Database backups: only the newest {n} are kept.', ['n' => $this->keep()]);
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# file} other{# files}}', ['n' => $count]);
    }

    public function enabled(): bool
    {
        return $this->settings->backupsEnabled;
    }

    public function keep(): int
    {
        return max(1, $this->settings->backupsKeep);
    }

    protected function folder(): ?string
    {
        return Craft::$app->getPath()->getDbBackupPath(false);
    }

    protected function patterns(): array
    {
        return ['*.sql', '*.sql.zip', '*.zip', '*.gz', '*.dump'];
    }

    protected function select(array $files): array
    {
        return array_slice($files, $this->keep());
    }
}
