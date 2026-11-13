<?php

namespace pixelwerft\datakeeper\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\datakeeper\helpers\NavPosition;

class NavPositionTest extends TestCase
{
    /** @return array<int, array{url: string}> */
    private static function items(string ...$urls): array
    {
        return array_map(fn(string $url) => ['url' => $url], $urls);
    }

    /** @return string[] */
    private static function urls(array $items): array
    {
        return array_column($items, 'url');
    }

    public function testSecondLastPutsTheItemBeforeTheLastOne(): void
    {
        $items = self::items('dashboard', 'entries', 'data-keeper', 'settings', 'plugin-store');
        $this->assertSame(
            ['dashboard', 'entries', 'settings', 'data-keeper', 'plugin-store'],
            self::urls(NavPosition::place($items, 'data-keeper', NavPosition::SECOND_LAST)),
        );
    }

    public function testSecondLastCountsItemsOtherPluginsAddedAtTheEnd(): void
    {
        $items = self::items('dashboard', 'data-keeper', 'settings', 'plugin-store', 'user-audit');
        $this->assertSame(
            ['dashboard', 'settings', 'plugin-store', 'data-keeper', 'user-audit'],
            self::urls(NavPosition::place($items, 'data-keeper', NavPosition::SECOND_LAST)),
        );
    }

    public function testLastPutsTheItemAtTheEnd(): void
    {
        $items = self::items('dashboard', 'data-keeper', 'settings', 'plugin-store');
        $this->assertSame(
            ['dashboard', 'settings', 'plugin-store', 'data-keeper'],
            self::urls(NavPosition::place($items, 'data-keeper', NavPosition::LAST)),
        );
    }

    public function testCraftLeavesTheOrderAlone(): void
    {
        $items = self::items('dashboard', 'data-keeper', 'settings');
        $this->assertSame($items, NavPosition::place($items, 'data-keeper', NavPosition::CRAFT));
    }

    public function testAnUnknownPositionLeavesTheOrderAlone(): void
    {
        $items = self::items('dashboard', 'data-keeper', 'settings');
        $this->assertSame($items, NavPosition::place($items, 'data-keeper', 'somewhere'));
    }

    public function testNothingMovesWhenTheItemIsMissing(): void
    {
        $items = self::items('dashboard', 'settings');
        $this->assertSame($items, NavPosition::place($items, 'data-keeper', NavPosition::LAST));
    }

    public function testTheOnlyItemStaysWhereItIs(): void
    {
        $items = self::items('data-keeper');
        $this->assertSame($items, NavPosition::place($items, 'data-keeper', NavPosition::SECOND_LAST));
    }

    public function testOtherKeysOfTheItemSurvive(): void
    {
        $items = [['url' => 'data-keeper', 'label' => 'Data Keeper', 'subnav' => ['a' => 1]], ['url' => 'settings']];
        $placed = NavPosition::place($items, 'data-keeper', NavPosition::LAST);
        $this->assertSame(['url' => 'data-keeper', 'label' => 'Data Keeper', 'subnav' => ['a' => 1]], $placed[1]);
    }
}
