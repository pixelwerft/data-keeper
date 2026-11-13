<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;
use craft\helpers\FileHelper;
use pixelwerft\datakeeper\DataKeeper;
use yii\base\InvalidArgumentException;

/**
 * Clears the caches the «Cache» key figure counts: Craft's data cache, the compiled templates and the compiled
 * classes – what Craft's own «Caches» utility does for these three. Nothing is lost, Craft builds them again.
 */
class ClearCaches extends Action
{
    public function id(): string
    {
        return 'clear-caches';
    }

    public function figure(): string
    {
        return 'cache';
    }

    public function button(): string
    {
        return self::t('Clear…');
    }

    public function label(): string
    {
        return self::t('Clear the caches');
    }

    public function description(): string
    {
        return self::t('Clears Craft’s data cache, the compiled templates and the compiled classes, as the «Caches» utility of Craft does for these three. Nothing is lost: Craft builds them again when they are needed, so the next page views take a little longer. The size on the card counts the cache files on disk; a cache that lives elsewhere (Redis, the database) is cleared too but not counted.');
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# file} other{# files}}', ['n' => $count]);
    }

    public function outcome(int $count): string
    {
        return self::t('The caches were cleared.');
    }

    public function permanent(): bool
    {
        return false;
    }

    public function preview(): array
    {
        $size = DataKeeper::$plugin->measurements->cacheSize();
        // a data cache outside the files (Redis, the database) has no files to count, but can still be cleared
        return ['count' => max(1, $size['files']), 'mb' => $size['mb'], 'sample' => []];
    }

    public function run(?callable $progress = null): array
    {
        $measurements = DataKeeper::$plugin->measurements;
        $before = $measurements->cacheSize()['files'];

        Craft::$app->getCache()->flush();
        $path = Craft::$app->getPath();
        foreach ([$path->getCompiledTemplatesPath(false), $path->getCompiledClassesPath(false)] as $folder) {
            try {
                FileHelper::clearDirectory($folder);
            } catch (InvalidArgumentException) {
                // the folder does not exist: nothing to clear
            }
        }

        return ['count' => max(0, $before - $measurements->cacheSize()['files']), 'problems' => []];
    }
}
