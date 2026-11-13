<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;
use craft\db\Query;
use craft\db\Table;

/**
 * Empties the trash: runs Craft's own garbage collection with «delete all trashed», as `craft gc --delete-all-trashed`
 * does. Craft knows what belongs to a deleted element (nested entries, relations, search index), so the work is left
 * to it.
 */
class ClearTrash extends Action
{
    private const PASSES = 3;

    public function id(): string
    {
        return 'clear-trash';
    }

    public function figure(): string
    {
        return 'deleted';
    }

    public function button(): string
    {
        return self::t('Clear…');
    }

    public function label(): string
    {
        return self::t('Empty the trash');
    }

    public function description(): string
    {
        return self::t('Runs Craft’s garbage collection and removes everything in the trash for good, without waiting for the period Craft normally keeps deleted items. Nothing in the trash can be restored afterwards.');
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# deleted element} other{# deleted elements}}', ['n' => $count]);
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# deleted element was} other{# deleted elements were}} removed for good.', ['n' => $count]);
    }

    public function preview(): array
    {
        return ['count' => $this->trashed(), 'mb' => null, 'sample' => []];
    }

    public function run(?callable $progress = null): array
    {
        $before = $this->trashed();
        $gc = Craft::$app->getGc();
        $deleteAllTrashed = $gc->deleteAllTrashed;
        $gc->deleteAllTrashed = true;
        try {
            // one pass removes the deleted versions of an entry, the entry itself only goes in the next one (Craft
            // keeps a deleted entry while it has versions): run until nothing more goes, a few times at most
            $left = $before;
            for ($pass = 0; $pass < self::PASSES && $left > 0; $pass++) {
                $gc->run(true);
                $now = $this->trashed();
                if ($now >= $left) {
                    break;
                }
                $left = $now;
                if ($progress) {
                    $progress($before - $left, $before);
                }
            }
        } finally {
            $gc->deleteAllTrashed = $deleteAllTrashed;
        }
        return ['count' => max(0, $before - $this->trashed()), 'problems' => []];
    }

    private function trashed(): int
    {
        return (int)(new Query())->from(Table::ELEMENTS)->where(['not', ['dateDeleted' => null]])->count();
    }
}
