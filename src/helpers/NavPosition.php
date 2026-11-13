<?php

namespace pixelwerft\datakeeper\helpers;

/**
 * Where the plugin's section sits in the control panel sidebar. Pure logic, no Craft dependency.
 */
final class NavPosition
{
    /** Second from the bottom (the default). */
    public const SECOND_LAST = 'secondLast';
    /** At the very bottom. */
    public const LAST = 'last';
    /** Where Craft puts plugin sections: the item is not moved. */
    public const CRAFT = 'craft';

    public const ALL = [self::SECOND_LAST, self::LAST, self::CRAFT];

    /**
     * Moves the item with the address $url to $position.
     *
     * @param array<int, array<string, mixed>> $items the sidebar items in their order
     * @return array<int, array<string, mixed>> the items in the new order (unchanged if the item is not there or the
     *         position is `craft`)
     */
    public static function place(array $items, string $url, string $position): array
    {
        if ($position !== self::SECOND_LAST && $position !== self::LAST) {
            return $items;
        }

        $own = null;
        $rest = [];
        foreach ($items as $item) {
            if ($own === null && ($item['url'] ?? null) === $url) {
                $own = $item;
            } else {
                $rest[] = $item;
            }
        }
        if ($own === null) {
            return $items;
        }

        $index = $position === self::LAST ? count($rest) : max(0, count($rest) - 1);
        array_splice($rest, $index, 0, [$own]);
        return $rest;
    }
}
