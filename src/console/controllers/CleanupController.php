<?php

namespace pixelwerft\datakeeper\console\controllers;

use craft\console\Controller;
use pixelwerft\datakeeper\DataKeeper;
use yii\console\ExitCode;

/**
 * Retention cleanup: `craft data-keeper/cleanup` is a dry run, `--apply` removes.
 *
 * Only rules that are switched on in the plugin settings ever run.
 */
class CleanupController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Really remove things. Without it every cleanup is a dry run. */
    public bool $apply = false;

    /** @var string|null Only this rule (an id from `cleanup/list`); it still has to be switched on in the settings. */
    public ?string $rule = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return $actionID === 'index' ? [...$options, 'apply', 'rule'] : $options;
    }

    /** Dry run by default; with --apply removes what the switched-on rules find. */
    public function actionIndex(): int
    {
        $report = DataKeeper::$plugin->cleanup->run(
            $this->apply,
            $this->rule !== null ? [$this->rule] : null,
            'console',
        );
        RunController::printReport($this, $report);
        if (!$this->apply) {
            $this->stdout("\nDry run – nothing was removed. Add --apply to remove it.\n");
        }
        return $report['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Lists the retention rules, whether they are on, and what each would remove. Removes nothing. */
    public function actionList(): int
    {
        foreach (DataKeeper::$plugin->cleanup->previews() as $id => $item) {
            $this->stdout(sprintf(
                "%-28s %-4s %s\n",
                $id,
                $item['rule']->enabled() ? 'ON' : 'off',
                $item['rule']->amount($item['preview']['count']),
            ));
        }
        return ExitCode::OK;
    }
}
