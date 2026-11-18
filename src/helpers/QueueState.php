<?php

namespace pixelwerft\datakeeper\helpers;

/**
 * Whether a queue is in trouble, judged from a few numbers. Pure logic, no Craft dependency.
 *
 * Three things can be wrong:
 * - `failed`: jobs failed and stay in the queue until someone retries or releases them;
 * - `hung`: a job is marked as running but has not reported for longer than it is given to run – its process died
 *   or it hangs;
 * - `stuck`: a job has been waiting for longer than the limit and nothing is being worked off – nobody runs the
 *   queue (or the only job that runs hangs).
 */
final class QueueState
{
    public const FAILED = 'failed';
    public const HUNG = 'hung';
    public const STUCK = 'stuck';

    /**
     * @param array{waiting: int, delayed: int, running: int, failed: int} $counts
     * @param int|null $oldestWaiting since when (Unix time) the job that waits longest could have run
     * @param array<int, array{description: string, updated: int, ttr: int}> $running the jobs marked as running:
     *        when each last reported (Unix time) and how many seconds it is given
     * @param int $waitMinutes how long a job may wait before the queue counts as not being worked off
     * @return array{status: string, problems: string[], waiting: int, delayed: int, running: int, failed: int,
     *         oldestWaitingMinutes: ?int, hungJob: ?string, hungMinutes: ?int}
     */
    public static function assess(array $counts, ?int $oldestWaiting, array $running, int $now, int $waitMinutes): array
    {
        $hungJob = null;
        $hungMinutes = null;
        $working = 0;
        foreach ($running as $job) {
            $quiet = $now - $job['updated'];
            if ($quiet > max(1, $job['ttr'])) {
                $minutes = intdiv($quiet, 60);
                if ($hungMinutes === null || $minutes > $hungMinutes) {
                    $hungMinutes = $minutes;
                    $hungJob = $job['description'];
                }
            } else {
                $working++;
            }
        }

        $waitedMinutes = $oldestWaiting !== null && $counts['waiting'] > 0 ? max(0, intdiv($now - $oldestWaiting, 60)) : null;

        $problems = [];
        if ($waitedMinutes !== null && $waitedMinutes >= max(1, $waitMinutes) && $working === 0) {
            $problems[] = self::STUCK;
        }
        if ($hungMinutes !== null) {
            $problems[] = self::HUNG;
        }
        if ($counts['failed'] > 0) {
            $problems[] = self::FAILED;
        }

        return [
            'status' => match (true) {
                in_array(self::STUCK, $problems, true), in_array(self::HUNG, $problems, true) => Levels::ALERT,
                $problems !== [] => Levels::WARN,
                default => Levels::OK,
            },
            'problems' => $problems,
            'waiting' => $counts['waiting'],
            'delayed' => $counts['delayed'],
            'running' => $counts['running'],
            'failed' => $counts['failed'],
            'oldestWaitingMinutes' => $waitedMinutes,
            'hungJob' => $hungJob,
            'hungMinutes' => $hungMinutes,
        ];
    }
}
