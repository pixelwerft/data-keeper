<?php

namespace pixelwerft\datakeeper\controllers;

use Craft;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\jobs\MaintenanceJob;
use pixelwerft\datakeeper\maintenance\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The actions of the key figure cards: a page that says what an action would do, and starting it in the background
 * after it was confirmed.
 */
class MaintenanceController extends Controller
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

    /** What the action would do right now. Nothing is changed by opening the page. */
    public function actionIndex(string $id): Response
    {
        $action = $this->find($id);
        return $this->renderTemplate('data-keeper/action', [
            'action' => $action,
            'preview' => $action->preview(),
        ]);
    }

    /**
     * Starts the action in the queue (never inline: emptying a big trash or rebuilding the tables takes longer than a
     * web request). For an action that removes data, the confirmed count guards against the data having changed
     * since the page was shown.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $action = $this->find((string)$this->request->getRequiredBodyParam('id'));
        $confirmed = (int)$this->request->getRequiredBodyParam('confirmedCount');
        $current = $action->preview()['count'];
        $page = 'data-keeper/action/' . $action->id();

        if ($current === 0) {
            Craft::$app->getSession()->setNotice(DataKeeper::t('Nothing to do.'));
            return $this->redirect($page);
        }
        if ($action->permanent() && $current !== $confirmed) {
            $this->setFailFlash(DataKeeper::t('The numbers changed in the meantime. Please check them again.'));
            return $this->redirect($page);
        }

        Queue::push(new MaintenanceJob([
            'id' => $action->id(),
            'confirmed' => $action->permanent() ? $confirmed : null,
            'source' => 'cp',
        ]));
        $this->setSuccessFlash(DataKeeper::t('«{action}» was queued. It runs in the background; the result appears in the overview.', ['action' => $action->label()]));
        return $this->redirect('data-keeper');
    }

    /** The files in the backup folder, each with a button that deletes it. */
    public function actionBackups(): Response
    {
        $folder = (string)Craft::$app->getPath()->getDbBackupPath(false);
        $root = rtrim((string)Craft::getAlias('@root'), '/') . '/';
        return $this->renderTemplate('data-keeper/backups', [
            'files' => DataKeeper::$plugin->maintenance->backupFiles(),
            'folder' => str_starts_with($folder, $root) ? substr($folder, strlen($root)) : $folder,
        ]);
    }

    /** Deletes one backup file (the browser asked «really?» before), then shows the list again. */
    public function actionDeleteBackup(): Response
    {
        $this->requirePostRequest();
        $name = (string)$this->request->getRequiredBodyParam('file');
        if (DataKeeper::$plugin->maintenance->deleteBackup($name)) {
            $this->setSuccessFlash(DataKeeper::t('«{file}» was deleted.', ['file' => $name]));
        } else {
            $this->setFailFlash(DataKeeper::t('«{file}» could not be deleted.', ['file' => $name]));
        }
        // back to the overview with the list open again, so the next file can go at once
        return $this->redirect(UrlHelper::cpUrl('data-keeper', ['open' => 'backups']));
    }

    private function find(string $id): Action
    {
        $action = DataKeeper::$plugin->maintenance->action($id);
        if ($action === null) {
            throw new NotFoundHttpException('Action not found.');
        }
        return $action;
    }
}
