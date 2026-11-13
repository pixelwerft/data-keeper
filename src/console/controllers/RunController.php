<?php

namespace pixelwerft\datakeeper\console\controllers;

use craft\console\Controller;
use craft\helpers\Queue;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\jobs\RunJob;
use yii\console\ExitCode;

/**
 * What a daily cron job calls: cleanup (only with --apply), then measure.
 *
 * ```
 * php craft data-keeper/run --apply
 * php craft data-keeper/run --apply --queue    (hands the work to the queue)
 * ```
 */
class RunController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Really remove things. Without it the cleanup part is a dry run. */
    public bool $apply = false;

    /** @var bool Put the work in the queue instead of doing it now. */
    public bool $queue = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return $actionID === 'index' ? [...$options, 'apply', 'queue'] : $options;
    }

    public function actionIndex(): int
    {
        if ($this->queue) {
            Queue::push(new RunJob(['apply' => $this->apply, 'source' => 'console']));
            $this->stdout("Queued.\n");
            return ExitCode::OK;
        }

        $plugin = DataKeeper::$plugin;
        $report = $plugin->cleanup->run($this->apply, null, 'console');
        self::printReport($this, $report);
        if (!$this->apply) {
            $this->stdout("Dry run – nothing was removed. Add --apply to remove it.\n");
        }
        $plugin->measurements->measure();
        $this->stdout("Measured.\n");
        return $report['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Prints the result of every rule. */
    public static function printReport(Controller $controller, array $report): void
    {
        if (!$report['results']) {
            $controller->stdout("No rule selected.\n");
            return;
        }
        foreach ($report['results'] as $id => $result) {
            $line = match ($result['status']) {
                'off' => 'off (not switched on, skipped)',
                'dry-run' => "would remove {$result['would']}",
                'done' => "removed {$result['removed']}",
                'changed' => "skipped: {$result['would']} now, more than confirmed",
                default => 'FAILED: ' . $result['error'],
            };
            $controller->stdout(sprintf("%-28s %s\n", $id, $line));
        }
    }
}
