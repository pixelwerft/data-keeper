<?php

namespace pixelwerft\datakeeper\console\controllers;

use craft\console\Controller;
use pixelwerft\datakeeper\DataKeeper;
use yii\console\ExitCode;

/**
 * The actions of the key figure cards on the console.
 *
 * ```
 * php craft data-keeper/maintenance/list                       (the actions and what each would work on)
 * php craft data-keeper/maintenance optimize-tables            (says what it would do)
 * php craft data-keeper/maintenance optimize-tables --apply    (does it, then measures)
 * ```
 */
class MaintenanceController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Really do it. Without it the command only says what the action would work on. */
    public bool $apply = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return $actionID === 'index' ? [...$options, 'apply'] : $options;
    }

    /**
     * Runs one action: clear-change-records, clear-trash, clear-logs, optimize-tables or check-tables.
     *
     * @param string $id the id of the action (see `maintenance/list`)
     */
    public function actionIndex(string $id): int
    {
        $plugin = DataKeeper::$plugin;
        $action = $plugin->maintenance->action($id);
        if ($action === null) {
            $this->stderr("Unknown action «{$id}» (or not available for this database). See data-keeper/maintenance/list.\n");
            return ExitCode::USAGE;
        }

        if (!$this->apply) {
            $this->stdout(sprintf("%-22s would work on %s\n", $id, $action->amount($action->preview()['count'])));
            $this->stdout("Nothing was done. Add --apply to run it.\n");
            return ExitCode::OK;
        }

        $report = $plugin->maintenance->run($id, null, 'console');
        $this->stdout(sprintf("%-22s %s\n", $id, $plugin->maintenance->describe($report)));
        foreach ($report['problems'] as $problem) {
            $this->stdout("  ! $problem\n");
        }
        $plugin->measurements->measure();
        $this->stdout("Measured.\n");
        return $report['status'] === 'done' && !$report['problems'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Lists the actions and what each would work on. Changes nothing. */
    public function actionList(): int
    {
        foreach (DataKeeper::$plugin->maintenance->actions() as $id => $action) {
            $this->stdout(sprintf("%-22s %s\n", $id, $action->amount($action->preview()['count'])));
        }
        return ExitCode::OK;
    }
}
