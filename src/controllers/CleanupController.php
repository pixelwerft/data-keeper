<?php

namespace pixelwerft\datakeeper\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\jobs\RunJob;
use yii\web\Response;

/**
 * The cleanup page: what every retention rule would remove, and starting a run in the background.
 */
class CleanupController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // the section itself first: the cleanup permission alone does not open its pages
        $this->requirePermission(DataKeeper::PERMISSION_ACCESS);
        $this->requirePermission(DataKeeper::PERMISSION_CLEANUP);
        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = DataKeeper::$plugin;
        $previews = $plugin->cleanup->previews();
        $enabled = array_filter($previews, fn($p) => $p['rule']->enabled());

        return $this->renderTemplate('data-keeper/cleanup', [
            'previews' => $previews,
            'enabledCount' => count($enabled),
            'wouldRemove' => array_sum(array_map(fn($p) => $p['preview']['count'], $enabled)),
            'retentions' => array_map(fn($p) => $p['rule']->retention(), $enabled),
            'runs' => array_slice($plugin->cleanup->runs(), 0, 10),
            'isAdmin' => Craft::$app->getUser()->getIsAdmin(),
        ]);
    }

    /**
     * Starts the cleanup in the queue (never inline: removing thousands of versions takes longer than a web request).
     * Only the rules that are switched on are run; the confirmed count guards against the data having changed since
     * the page was shown.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $confirmed = (int)$this->request->getRequiredBodyParam('confirmedCount');
        $plugin = DataKeeper::$plugin;
        $current = 0;
        $perRule = [];
        foreach ($plugin->cleanup->previews() as $id => $item) {
            if ($item['rule']->enabled()) {
                $current += $item['preview']['count'];
                $perRule[$id] = $item['preview']['count'];
            }
        }
        if ($current !== $confirmed) {
            $this->setFailFlash(DataKeeper::t('The numbers changed in the meantime. Please check them again.'));
            return $this->redirect('data-keeper/cleanup');
        }
        if ($current === 0) {
            Craft::$app->getSession()->setNotice(DataKeeper::t('Nothing to remove.'));
            return $this->redirect('data-keeper/cleanup');
        }

        Queue::push(new RunJob(['apply' => true, 'source' => 'cp', 'confirmed' => $perRule]));
        $this->setSuccessFlash(DataKeeper::t('The cleanup was queued. It runs in the background; the result appears below and in the overview.'));
        return $this->redirect('data-keeper/cleanup');
    }
}
