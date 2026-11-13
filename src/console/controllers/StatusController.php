<?php

namespace pixelwerft\datakeeper\console\controllers;

use craft\console\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Levels;
use yii\console\ExitCode;

/**
 * Prints what needs attention: `craft data-keeper/status`.
 * Exit code 0 ok, 1 «keep an eye on it», 2 «act now» – usable from a shell monitor.
 */
class StatusController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $status = DataKeeper::$plugin->notifications->status();
        $this->stdout(strtoupper($status['status']) . "\n");
        foreach ($status['conditions'] as $condition) {
            $this->stdout(sprintf("  [%s] %s – %s\n", $condition['level'], $condition['title'], $condition['message']));
        }
        return match ($status['status']) {
            Levels::ALERT => 2,
            Levels::WARN => 1,
            default => ExitCode::OK,
        };
    }
}
