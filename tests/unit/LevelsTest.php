<?php

namespace pixelwerft\datakeeper\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pixelwerft\datakeeper\helpers\Levels;

class LevelsTest extends TestCase
{
    public static function values(): array
    {
        return [
            'below warn' => [['warn' => 100, 'alert' => 200], 99.9, Levels::OK],
            'at warn' => [['warn' => 100, 'alert' => 200], 100, Levels::WARN],
            'between' => [['warn' => 100, 'alert' => 200], 150, Levels::WARN],
            'at alert' => [['warn' => 100, 'alert' => 200], 200, Levels::ALERT],
            'zero switches a check off' => [['warn' => 0, 'alert' => 0], 999999, Levels::OK],
            'only alert set' => [['warn' => 0, 'alert' => 50], 60, Levels::ALERT],
            'no limit at all' => [null, 5, Levels::OK],
        ];
    }

    #[DataProvider('values')]
    public function testForValue(?array $limit, int|float $value, string $expected): void
    {
        $this->assertSame($expected, Levels::forValue($limit, $value));
    }

    public function testWorstPicksTheMostSevereLevel(): void
    {
        $this->assertSame(Levels::OK, Levels::worst([]));
        $this->assertSame(Levels::WARN, Levels::worst([Levels::OK, Levels::INFO, Levels::WARN]));
        $this->assertSame(Levels::ALERT, Levels::worst([Levels::WARN, Levels::ALERT, Levels::OK]));
    }

    public function testAtLeast(): void
    {
        $this->assertTrue(Levels::atLeast(Levels::ALERT, Levels::WARN));
        $this->assertTrue(Levels::atLeast(Levels::WARN, Levels::WARN));
        $this->assertFalse(Levels::atLeast(Levels::INFO, Levels::WARN));
    }
}
