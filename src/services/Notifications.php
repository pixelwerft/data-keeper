<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Levels;

/**
 * Tells the site owner where and when something needs attention.
 *
 * The topics are switched on one by one in the settings (all off by default). What a topic found is available in
 * three ways: as a notice in the control panel, as a message pushed out (e-mail, webhook) when something new comes
 * up, and as a status endpoint that a monitoring tool can poll.
 *
 * A condition is pushed when it is new, when it gets worse, or again after «remind after» days while it stays.
 */
class Notifications extends Component
{
    /**
     * What needs attention right now, from the topics that are switched on.
     *
     * @return array<int, array{key: string, topic: string, level: string, title: string, message: string}>
     */
    public function conditions(?array $last = null): array
    {
        $plugin = DataKeeper::$plugin;
        $settings = $plugin->getSettings();
        $last ??= $plugin->measurements->last();
        $items = [];

        // numbers as the pages show them: grouped, one decimal only where there is one
        $number = fn(int|float $n): string => Craft::$app->getFormatter()->asDecimal($n, $n == round($n) ? 0 : 1);

        if ($settings->notifyLevels && $last) {
            foreach ($plugin->measurements->limits() as $key => $limit) {
                $value = $last['figures'][$key] ?? 0;
                $level = Levels::forValue($limit, $value);
                if ($level === Levels::OK || !Levels::atLeast($level, $settings->minLevel)) {
                    continue;
                }
                $unit = $limit['unit'] ? ' ' . $limit['unit'] : '';
                $items[] = [
                    'key' => "levels:$key",
                    'topic' => 'levels',
                    'level' => $level,
                    'title' => self::t('{figure}: {value}{unit}', [
                        'figure' => self::t($limit['label']),
                        'value' => $number($value),
                        'unit' => $unit,
                    ]),
                    'message' => $level === Levels::ALERT
                        ? self::t('Reached the «act now» limit of {limit}{unit}.', ['limit' => $number($limit['alert']), 'unit' => $unit])
                        : self::t('Reached the «keep an eye on it» limit of {limit}{unit}.', ['limit' => $number($limit['warn']), 'unit' => $unit]),
                ];
            }
        }

        if ($settings->notifyStale) {
            $age = $last ? (time() - strtotime($last['measuredAt'])) / 86400 : null;
            if ($age === null) {
                $items[] = [
                    'key' => 'stale',
                    'topic' => 'stale',
                    'level' => Levels::WARN,
                    'title' => self::t('Never measured'),
                    'message' => self::t('The site has not been measured yet.'),
                ];
            } elseif ($age >= $settings->staleAfterDays) {
                $days = (int)floor($age);
                $items[] = [
                    'key' => 'stale',
                    'topic' => 'stale',
                    'level' => $age >= 3 * $settings->staleAfterDays ? Levels::ALERT : Levels::WARN,
                    'title' => self::t('No measurement for {n} days', ['n' => $days]),
                    'message' => self::t('The last measurement is older than {n} days. Is the daily job running?', ['n' => $settings->staleAfterDays]),
                ];
            }
        }

        if ($settings->notifyBacklog && $last) {
            $rules = $plugin->cleanup->allRules();
            foreach ($last['backlog'] ?? [] as $id => $entry) {
                if ($entry['count'] < $settings->backlogItems || !isset($rules[$id])) {
                    continue;
                }
                $rule = $rules[$id];
                $items[] = [
                    'key' => "backlog:$id",
                    'topic' => 'backlog',
                    'level' => Levels::WARN,
                    'title' => self::t('Old data waiting: {rule}', ['rule' => $rule->label()]),
                    'message' => self::t('{amount} are past the retention period of this rule ({state}).', [
                        'amount' => $rule->amount($entry['count']),
                        'state' => $entry['enabled'] ? self::t('rule is on – run the cleanup') : self::t('rule is off'),
                    ]),
                ];
            }
        }

        if ($settings->notifyCleanup) {
            $cleanup = $plugin->store->get('lastCleanup');
            if ($cleanup && $cleanup['error']) {
                $items[] = [
                    'key' => 'cleanup:failed:' . $cleanup['at'],
                    'topic' => 'cleanup',
                    'level' => Levels::ALERT,
                    'title' => self::t('The last cleanup failed'),
                    'message' => (string)$cleanup['error'],
                ];
            }
        }

        return $items;
    }

    /**
     * The status as the endpoint shows it.
     *
     * @return array{status: string, site: string, measuredAt: ?string, url: string, conditions: array}
     */
    public function status(): array
    {
        $conditions = $this->conditions();
        $last = DataKeeper::$plugin->measurements->last();
        return [
            'status' => Levels::worst(array_column($conditions, 'level')),
            'site' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'measuredAt' => $last['measuredAt'] ?? null,
            'url' => UrlHelper::cpUrl('data-keeper'),
            'conditions' => $conditions,
        ];
    }

    /** The notice for the control panel (HTML), or null if nothing needs attention or the channel is off. */
    public function cpAlert(): ?string
    {
        if (!DataKeeper::$plugin->getSettings()->channelCp) {
            return null;
        }
        $items = array_filter($this->conditions(), fn($c) => Levels::atLeast($c['level'], Levels::WARN));
        if (!$items) {
            return null;
        }
        $titles = implode(' · ', array_map(fn($c) => Html::encode($c['title']), array_slice($items, 0, 3)));
        $more = count($items) > 3 ? ' ' . Html::encode(self::t('and {n} more', ['n' => count($items) - 3])) : '';
        return Html::encode(self::t('Data Keeper:')) . ' ' . $titles . $more . ' '
            . Html::a(Html::encode(self::t('Review')), UrlHelper::cpUrl('data-keeper'), ['class' => 'go']);
    }

    /**
     * Called after every measurement: pushes what is new (e-mail, webhook) and remembers what was pushed, so the
     * same thing is not sent every day.
     *
     * The decision «send or not» is made under the store's lock and the keys are reserved there, so a cron run and a
     * click on «Measure now» at the same moment do not both send. If sending fails, the reservation is taken back
     * and the next run tries again.
     */
    public function refresh(array $last): void
    {
        $plugin = DataKeeper::$plugin;
        $settings = $plugin->getSettings();
        $conditions = $this->conditions($last);
        $hasPush = $this->hasPushChannel();
        $remind = $settings->remindAfterDays * 86400;
        $now = time();
        $toSend = [];

        $plugin->store->update(function(array $data) use ($conditions, $hasPush, $remind, $now, $settings, &$toSend) {
            $state = $data['notified'] ?? [];
            $new = [];
            foreach ($conditions as $condition) {
                $key = $condition['key'];
                $known = $state[$key] ?? null;
                $worse = $known !== null && Levels::rank($condition['level']) > Levels::rank($known['level']);
                $due = $known !== null && $remind > 0 && $now - strtotime($known['at']) >= $remind;
                if ($hasPush && ($known === null || $worse || $due)) {
                    $toSend[] = $condition;
                    $new[$key] = ['level' => $condition['level'], 'at' => date(DATE_ATOM, $now)];
                } elseif ($known !== null) {
                    // a lower level is remembered, so it is sent again if it climbs back
                    $new[$key] = ['level' => $condition['level'], 'at' => $known['at']];
                }
                // without a push channel nothing is marked as sent: switching a channel on later reports what is current
            }
            $data['notified'] = $new;

            // a cleanup that just finished is announced once (not a run from long ago)
            $cleanup = $data['lastCleanup'] ?? null;
            if ($cleanup && !$cleanup['announced']) {
                $recent = $now - strtotime($cleanup['at']) < 2 * 86400;
                if ($settings->notifyCleanup && $hasPush && $recent && !$cleanup['error'] && $cleanup['removed'] > 0) {
                    $toSend[] = [
                        'key' => 'cleanup:done',
                        'topic' => 'cleanup',
                        'level' => Levels::INFO,
                        'title' => self::t('Cleanup finished'),
                        'message' => self::t('{n} items were removed.', ['n' => Craft::$app->getFormatter()->asInteger($cleanup['removed'])]),
                    ];
                }
                if ($hasPush || !$settings->notifyCleanup) {
                    $data['lastCleanup']['announced'] = true;
                }
            }
            return $data;
        });

        if (!$toSend) {
            return;
        }
        $results = $this->push($toSend);
        $allSent = $results && !array_filter($results, fn($r) => $r !== true);
        if ($allSent) {
            return;
        }

        // at least one channel failed: take the reservation back, so the next run tries again
        $keys = array_column($toSend, 'key');
        $plugin->store->update(function(array $data) use ($keys) {
            foreach ($keys as $key) {
                unset($data['notified'][$key]);
            }
            if (in_array('cleanup:done', $keys, true)) {
                $data['lastCleanup']['announced'] = false;
            }
            return $data;
        });
    }

    public function hasPushChannel(): bool
    {
        $settings = DataKeeper::$plugin->getSettings();
        return ($settings->channelEmail && $settings->recipients())
            || ($settings->channelWebhook && $settings->webhook() !== '');
    }

    /**
     * Sends a test message through the switched-on channels.
     *
     * @return array<string, bool|string> channel => true or the error
     */
    public function sendTest(): array
    {
        return $this->push([[
            'key' => 'test',
            'topic' => 'test',
            'level' => Levels::INFO,
            'title' => self::t('Test message'),
            'message' => self::t('This is a test from Data Keeper. If you can read this, the channel works.'),
        ]]);
    }

    /**
     * @param array<int, array{key: string, topic: string, level: string, title: string, message: string}> $items
     * @return array<string, bool|string>
     */
    private function push(array $items): array
    {
        $settings = DataKeeper::$plugin->getSettings();
        $results = [];
        if ($settings->channelEmail && $settings->recipients()) {
            $results['email'] = $this->sendEmail($items, $settings->recipients());
        }
        if ($settings->channelWebhook && $settings->webhook() !== '') {
            $results['webhook'] = $this->sendWebhook($items, $settings->webhook(), $settings->secret());
        }
        return $results;
    }

    private function sendEmail(array $items, array $recipients): bool|string
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $lines = array_map(fn($i) => '[' . strtoupper($i['level']) . '] ' . $i['title'] . "\n    " . $i['message'], $items);
        $body = implode("\n\n", $lines) . "\n\n" . self::t('Overview:') . ' ' . UrlHelper::cpUrl('data-keeper') . "\n";
        try {
            $sent = Craft::$app->getMailer()->compose()
                ->setTo($recipients)
                ->setSubject(self::t('[{site}] Data Keeper: {n} item(s) need attention', ['site' => $site, 'n' => count($items)]))
                ->setTextBody($body)
                ->send();
            return $sent ?: 'The mailer did not send the message.';
        } catch (\Throwable $e) {
            Craft::warning('Notification e-mail failed: ' . $e->getMessage(), __METHOD__);
            return $e->getMessage();
        }
    }

    private function sendWebhook(array $items, string $url, string $secret): bool|string
    {
        $body = json_encode([
            'source' => 'data-keeper',
            'site' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'url' => UrlHelper::cpUrl('data-keeper'),
            'sentAt' => date(DATE_ATOM),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];
        if ($secret !== '') {
            $headers['X-Data-Keeper-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }
        try {
            Craft::createGuzzleClient(['timeout' => 10, 'connect_timeout' => 5])->post($url, ['body' => $body, 'headers' => $headers]);
            return true;
        } catch (\Throwable $e) {
            Craft::warning('Notification webhook failed: ' . $e->getMessage(), __METHOD__);
            return $e->getMessage();
        }
    }

    private static function t(string $message, array $params = []): string
    {
        return Craft::t('data-keeper', $message, $params);
    }
}
