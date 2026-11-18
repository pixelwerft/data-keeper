<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\i18n\Translation;
use craft\queue\Queue;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\QueueState;

/**
 * How Craft's queue is doing: how many jobs wait, run and failed, and whether it is in trouble – jobs failed, a job
 * hangs, or nothing is being worked off at all.
 *
 * Read when it is asked for, not stored with a measurement: a queue that hangs is exactly the case in which a
 * measurement might not run. A handful of small queries on Craft's queue table; nothing is changed.
 */
class QueueHealth extends Component
{
    /** @var array<string, mixed>|false|null what was read in this request (false: the queue cannot be looked into) */
    private array|false|null $state = null;

    /**
     * @return array{status: string, problems: string[], waiting: int, delayed: int, running: int, failed: int,
     *         oldestWaitingMinutes: ?int, hungJob: ?string, hungMinutes: ?int}|null null if the queue is not Craft's
     *         own (another driver) or could not be read
     */
    public function state(): ?array
    {
        if ($this->state === null) {
            try {
                $this->state = $this->read() ?? false;
            } catch (\Throwable $e) {
                Craft::warning('Could not look into the queue: ' . $e->getMessage(), __METHOD__);
                $this->state = false;
            }
        }
        return $this->state ?: null;
    }

    /**
     * What is wrong with the queue, as the overview, the notifications and the status endpoint show it.
     *
     * @return array<int, array{key: string, topic: string, level: string, title: string, message: string}>
     */
    public function conditions(): array
    {
        $state = $this->state();
        if ($state === null) {
            return [];
        }

        $items = [];
        foreach ($state['problems'] as $problem) {
            $items[] = ['key' => "queue:$problem", 'topic' => 'queue'] + match ($problem) {
                QueueState::STUCK => [
                    'level' => 'alert',
                    'title' => self::t('Queue: nothing is being worked off'),
                    'message' => self::t('The oldest job has been waiting for {n, plural, one{# minute} other{# minutes}} and none is running. Is the queue being run on this site – by a cron job or daemon, or with «runQueueAutomatically»?', ['n' => $state['oldestWaitingMinutes']]),
                ],
                QueueState::HUNG => [
                    'level' => 'alert',
                    'title' => self::t('Queue: a job hangs'),
                    'message' => self::t('«{job}» has not reported for {n, plural, one{# minute} other{# minutes}}, longer than it is given to run. Craft tries it again by itself; if it keeps hanging, look at it in the queue manager.', ['job' => $state['hungJob'], 'n' => $state['hungMinutes']]),
                ],
                default => [
                    'level' => 'warn',
                    'title' => self::t('Queue: {n, plural, one{# failed job} other{# failed jobs}}', ['n' => $state['failed']]),
                    'message' => self::t('Failed jobs stay in the queue until someone runs them again or releases them in the queue manager.'),
                ],
            };
        }
        return $items;
    }

    /**
     * The queue as a monitoring tool gets it with a remote query: plain numbers and the names of what is wrong.
     *
     * @return array<string, mixed>|null
     */
    public function forRemote(): ?array
    {
        $state = $this->state();
        if ($state === null) {
            return null;
        }
        return [
            'status' => $state['status'],
            'problems' => $state['problems'],
            'waiting' => $state['waiting'],
            'delayed' => $state['delayed'],
            'running' => $state['running'],
            'failed' => $state['failed'],
            'oldestWaitingMinutes' => $state['oldestWaitingMinutes'],
            'hungMinutes' => $state['hungMinutes'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            return null;
        }
        $db = $queue->db;
        $now = time();
        // the jobs of this queue: without a channel of its own, Craft files them under the name of the component
        $channel = $queue->channel ?? 'queue';
        $jobs = fn(): Query => (new Query())->from($queue->tableName)->where(['channel' => $channel]);
        // a job that is neither failed nor taken waits – or is delayed, if its time has not come yet
        $open = fn(): Query => $jobs()->andWhere(['fail' => false, 'timeUpdated' => null]);
        $due = '[[timePushed]] + [[delay]] <= :now';

        $running = [];
        $rows = $jobs()
            ->select(['description', 'timeUpdated', 'ttr'])
            ->andWhere(['fail' => false])
            ->andWhere(['not', ['timeUpdated' => null]])
            ->orderBy(['timeUpdated' => SORT_ASC])
            ->limit(50)
            ->all($db);
        foreach ($rows as $row) {
            $running[] = [
                'description' => Translation::translate((string)$row['description']) ?: self::t('a job without a name'),
                'updated' => (int)$row['timeUpdated'],
                'ttr' => (int)$row['ttr'],
            ];
        }

        $oldest = $open()->andWhere($due, [':now' => $now])->min('[[timePushed]] + [[delay]]', $db);

        return QueueState::assess(
            [
                'waiting' => (int)$open()->andWhere($due, [':now' => $now])->count('*', $db),
                'delayed' => (int)$open()->andWhere('[[timePushed]] + [[delay]] > :now', [':now' => $now])->count('*', $db),
                'running' => (int)$jobs()->andWhere(['fail' => false])->andWhere(['not', ['timeUpdated' => null]])->count('*', $db),
                'failed' => (int)$jobs()->andWhere(['fail' => true])->count('*', $db),
            ],
            $oldest === null || $oldest === false ? null : (int)$oldest,
            $running,
            $now,
            DataKeeper::$plugin->getSettings()->queueWaitMinutes,
        );
    }

    private static function t(string $message, array $params = []): string
    {
        return Craft::t('data-keeper', $message, $params);
    }
}
