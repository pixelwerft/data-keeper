<?php

namespace pixelwerft\datakeeper\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\datakeeper\helpers\QueueState;

class QueueStateTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private static function counts(int $waiting = 0, int $running = 0, int $failed = 0, int $delayed = 0): array
    {
        return ['waiting' => $waiting, 'delayed' => $delayed, 'running' => $running, 'failed' => $failed];
    }

    private static function job(int $quietSeconds, int $ttr = 300, string $description = 'A job'): array
    {
        return ['description' => $description, 'updated' => self::NOW - $quietSeconds, 'ttr' => $ttr];
    }

    public function testAnEmptyQueueIsFine(): void
    {
        $state = QueueState::assess(self::counts(), null, [], self::NOW, 15);

        $this->assertSame('ok', $state['status']);
        $this->assertSame([], $state['problems']);
        $this->assertNull($state['oldestWaitingMinutes']);
    }

    public function testJobsThatJustArrivedAreFine(): void
    {
        $state = QueueState::assess(self::counts(waiting: 3), self::NOW - 120, [], self::NOW, 15);

        $this->assertSame('ok', $state['status']);
        $this->assertSame(2, $state['oldestWaitingMinutes']);
    }

    public function testWaitingTooLongWithNothingRunningIsStuck(): void
    {
        $state = QueueState::assess(self::counts(waiting: 3), self::NOW - 16 * 60, [], self::NOW, 15);

        $this->assertSame('alert', $state['status']);
        $this->assertSame([QueueState::STUCK], $state['problems']);
        $this->assertSame(16, $state['oldestWaitingMinutes']);
    }

    public function testWaitingBehindAJobThatWorksIsNotStuck(): void
    {
        $state = QueueState::assess(self::counts(waiting: 3, running: 1), self::NOW - 3600, [self::job(20, 3600)], self::NOW, 15);

        $this->assertSame('ok', $state['status']);
        $this->assertSame([], $state['problems']);
    }

    public function testAJobThatStoppedReportingHangs(): void
    {
        $state = QueueState::assess(self::counts(running: 1), null, [self::job(1000, 300, 'Resaving entries')], self::NOW, 15);

        $this->assertSame('alert', $state['status']);
        $this->assertSame([QueueState::HUNG], $state['problems']);
        $this->assertSame('Resaving entries', $state['hungJob']);
        $this->assertSame(16, $state['hungMinutes']);
    }

    public function testWaitingBehindAHungJobIsStuckToo(): void
    {
        $state = QueueState::assess(self::counts(waiting: 2, running: 1), self::NOW - 3600, [self::job(4000)], self::NOW, 15);

        $this->assertSame([QueueState::STUCK, QueueState::HUNG], $state['problems']);
    }

    public function testFailedJobsAreAWarning(): void
    {
        $state = QueueState::assess(self::counts(failed: 2), null, [], self::NOW, 15);

        $this->assertSame('warn', $state['status']);
        $this->assertSame([QueueState::FAILED], $state['problems']);
        $this->assertSame(2, $state['failed']);
    }

    public function testDelayedJobsDoNotCount(): void
    {
        $state = QueueState::assess(self::counts(delayed: 5), null, [], self::NOW, 15);

        $this->assertSame('ok', $state['status']);
        $this->assertNull($state['oldestWaitingMinutes']);
    }

    public function testTheLongestHangIsNamed(): void
    {
        $state = QueueState::assess(self::counts(running: 2), null, [self::job(700, 300, 'Short'), self::job(7300, 300, 'Long')], self::NOW, 15);

        $this->assertSame('Long', $state['hungJob']);
        $this->assertSame(121, $state['hungMinutes']);
    }
}
