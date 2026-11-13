<?php

namespace pixelwerft\datakeeper\services;

use craft\base\Component;
use pixelwerft\datakeeper\cleanup\BackupsRule;
use pixelwerft\datakeeper\cleanup\ChangeRecordsRule;
use pixelwerft\datakeeper\cleanup\LogsRule;
use pixelwerft\datakeeper\cleanup\RevisionsRule;
use pixelwerft\datakeeper\cleanup\Rule;
use pixelwerft\datakeeper\cleanup\TableRule;
use pixelwerft\datakeeper\DataKeeper;

/**
 * The retention rules: shows what each would remove (preview) and runs the ones that are switched on.
 *
 * Nothing is removed unless a rule is switched on in the settings and the run is applied – a run without `$apply` is
 * a dry run that only reports.
 */
class Cleanup extends Component
{
    private const KEEP_RUNS = 100;

    /** @return array<string, Rule> by rule id */
    public function allRules(): array
    {
        $settings = DataKeeper::$plugin->getSettings();
        $rules = [
            new RevisionsRule($settings),
            new BackupsRule($settings),
            new LogsRule($settings),
            new ChangeRecordsRule($settings),
        ];
        foreach ($settings->customRuleRows() as $row) {
            if ($row['table'] !== '') {
                $rules[] = new TableRule($settings, $row);
            }
        }
        $byId = [];
        foreach ($rules as $rule) {
            $byId[$rule->id()] = $rule;
        }
        return $byId;
    }

    /**
     * What every rule would remove now (also the ones that are switched off), for the page.
     *
     * @return array<string, array{rule: Rule, preview: array}>
     */
    public function previews(): array
    {
        $out = [];
        foreach ($this->allRules() as $id => $rule) {
            $out[$id] = ['rule' => $rule, 'preview' => $rule->preview()];
        }
        return $out;
    }

    /**
     * The counts per rule for the measurement: how much old data is waiting. Only ids and numbers – names are looked
     * up when shown, so they come out in the language of whoever reads them.
     *
     * @return array<string, array{count: int, mb: ?float, enabled: bool}>
     */
    public function backlog(): array
    {
        $out = [];
        foreach ($this->previews() as $id => $item) {
            $out[$id] = [
                'count' => $item['preview']['count'],
                'mb' => $item['preview']['mb'],
                'enabled' => $item['rule']->enabled(),
            ];
        }
        return $out;
    }

    /**
     * Runs the rules that are switched on.
     *
     * @param bool $apply false: dry run, only reports what would be removed
     * @param string[]|null $only run only these rule ids (they still have to be switched on)
     * @param string $source who runs it: `console`, `queue`, `cp`
     * @param callable(string, int, int): void|null $progress receives (rule id, done, total)
     * @param array<string, int>|null $confirmed what a person confirmed per rule id: a rule that would now remove more
     *        than that is skipped (status `changed`), so a run never removes more than was shown and confirmed
     * @return array{at: string, apply: bool, source: string, ok: bool, removed: int, results: array<string, array>}
     */
    public function run(bool $apply, ?array $only = null, string $source = 'console', ?callable $progress = null, ?array $confirmed = null): array
    {
        $results = [];
        $removedTotal = 0;
        $ok = true;

        foreach ($this->allRules() as $id => $rule) {
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            if (!$rule->enabled()) {
                $results[$id] = ['label' => $rule->label(), 'status' => 'off', 'would' => null, 'removed' => 0, 'error' => null];
                continue;
            }
            $would = $rule->preview()['count'];
            if ($apply && $confirmed !== null && $would > ($confirmed[$id] ?? 0)) {
                $results[$id] = ['label' => $rule->label(), 'status' => 'changed', 'would' => $would, 'removed' => 0, 'error' => null];
                continue;
            }
            if (!$apply) {
                $results[$id] = ['label' => $rule->label(), 'status' => 'dry-run', 'would' => $would, 'removed' => 0, 'error' => null];
                continue;
            }
            try {
                $removed = $rule->run($progress ? fn(int $done, int $total) => $progress($id, $done, $total) : null);
                $removedTotal += $removed;
                $results[$id] = ['label' => $rule->label(), 'status' => 'done', 'would' => $would, 'removed' => $removed, 'error' => null];
            } catch (\Throwable $e) {
                $ok = false;
                \Craft::error("Rule $id failed: " . $e->getMessage(), __METHOD__);
                $results[$id] = ['label' => $rule->label(), 'status' => 'failed', 'would' => $would, 'removed' => 0, 'error' => $e->getMessage()];
            }
        }

        $report = [
            'at' => (new \DateTime())->format(DATE_ATOM),
            'apply' => $apply,
            'source' => $source,
            'ok' => $ok,
            'removed' => $removedTotal,
            'results' => $results,
        ];
        if ($apply) {
            $this->log($report);
        }
        return $report;
    }

    /** @return array<int, array> the last runs, newest first */
    public function runs(): array
    {
        return array_reverse(DataKeeper::$plugin->store->get('runs', []));
    }

    private static function t(string $message): string
    {
        return \Craft::t('data-keeper', $message);
    }

    private function log(array $report): void
    {
        // what is stored (and shown, mailed, served at the endpoint) never carries the raw exception text, which can
        // hold SQL or file paths; the details are in the Craft log
        $error = null;
        foreach ($report['results'] as $id => $result) {
            if ($result['error']) {
                $error ??= $result['label'] . ': ' . self::t('failed – see the Craft log for details');
                $report['results'][$id]['error'] = self::t('failed – see the Craft log for details');
            }
        }
        DataKeeper::$plugin->store->update(function(array $data) use ($report, $error) {
            $runs = $data['runs'] ?? [];
            $runs[] = $report;
            $data['runs'] = array_slice($runs, -self::KEEP_RUNS);
            $data['lastCleanup'] = [
                'at' => $report['at'],
                'ok' => $report['ok'],
                'removed' => $report['removed'],
                'error' => $error,
                'announced' => false,
            ];
            return $data;
        });
    }
}
