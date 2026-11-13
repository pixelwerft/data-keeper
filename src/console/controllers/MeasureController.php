<?php

namespace pixelwerft\datakeeper\console\controllers;

use craft\console\Controller;
use pixelwerft\datakeeper\DataKeeper;
use yii\console\ExitCode;

/**
 * Measures the site and stores today's key figures: `craft data-keeper/measure`.
 */
class MeasureController extends Controller
{
    public $defaultAction = 'index';

    /** Measures the site and stores the key figures. */
    public function actionIndex(): int
    {
        $last = DataKeeper::$plugin->measurements->measure();
        $this->stdout("Measured at {$last['measuredAt']}\n");
        foreach ($last['figures'] as $key => $value) {
            $this->stdout(sprintf("  %-15s %s\n", $key, $value));
        }
        return ExitCode::OK;
    }
}
