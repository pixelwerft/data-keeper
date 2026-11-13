<?php

namespace pixelwerft\datakeeper\cleanup;

use pixelwerft\datakeeper\DataKeeper;

/**
 * Keeps the newest N saved versions of every element, removes the older ones.
 */
class RevisionsRule extends Rule
{
    public function id(): string
    {
        return 'revisions';
    }

    public function label(): string
    {
        return self::t('Saved versions');
    }

    public function description(): string
    {
        $text = $this->byAge()
            ? self::t('Permanently removes saved versions older than {days} days, but always keeps the newest {n} of every entry.', ['days' => $this->maxAgeDays(), 'n' => $this->keep()])
            : self::t('Keeps the newest {n} saved versions of every entry and permanently removes the older ones.', ['n' => $this->keep()]);
        return $this->keepFirst() ? $text . ' ' . self::t('The very first version of every entry is kept as well.') : $text;
    }

    public function retention(): string
    {
        $text = $this->byAge()
            ? self::t('Saved versions of content are removed {days} days after they were saved; the newest {n} per entry are always kept.', ['days' => $this->maxAgeDays(), 'n' => $this->keep()])
            : self::t('Older saved versions of content are removed, only the newest {n} per entry are kept.', ['n' => $this->keep()]);
        return $this->keepFirst() ? $text . ' ' . self::t('The very first version of every entry is kept as well.') : $text;
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# version} other{# versions}}', ['n' => $count]);
    }

    public function enabled(): bool
    {
        return $this->settings->revisionsEnabled;
    }

    public function keep(): int
    {
        return max(1, $this->settings->revisionsKeep);
    }

    public function keepFirst(): bool
    {
        return $this->settings->revisionsKeepFirst;
    }

    public function byAge(): bool
    {
        return $this->settings->revisionsMode === 'age';
    }

    public function maxAgeDays(): int
    {
        return max(30, $this->settings->revisionsMaxAgeDays);
    }

    /** The age limit passed to {@see \pixelwerft\datakeeper\services\Versions}: 0 (any age) in mode `count`. */
    private function olderThanDays(): int
    {
        return $this->byAge() ? $this->maxAgeDays() : 0;
    }

    public function preview(): array
    {
        $versions = DataKeeper::$plugin->versions;
        $rows = $versions->overLimit($this->keep(), $this->keepFirst(), $this->olderThanDays());

        return [
            'count' => array_sum(array_column($rows, 'n')),
            'mb' => null,
            'oldest' => null,
            'sample' => array_map(
                fn($r) => self::t('{title}: {n} of {total} versions', [
                    'title' => $r['title'] ?: '#' . $r['canonicalId'],
                    'n' => $r['n'],
                    'total' => $versions->count($r['canonicalId']),
                ]),
                array_slice($rows, 0, 5),
            ),
        ];
    }

    public function run(?callable $progress = null): int
    {
        $versions = DataKeeper::$plugin->versions;
        $rows = $versions->overLimit($this->keep(), $this->keepFirst(), $this->olderThanDays());
        $total = count($rows);
        $removed = 0;
        foreach ($rows as $i => $row) {
            $removed += $versions->clear($row['canonicalId'], $this->keep(), $this->keepFirst(), $this->olderThanDays());
            if ($progress) {
                $progress($i + 1, $total);
            }
        }
        return $removed;
    }
}
