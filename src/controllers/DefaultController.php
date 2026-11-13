<?php

namespace pixelwerft\datakeeper\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Growth;
use pixelwerft\datakeeper\helpers\Levels;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The overview page, measuring, and clearing the saved versions of one element.
 */
class DefaultController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * The last measurement. «Measure now» is a POST (`measure`), so nothing measures by opening the page.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-data-keeper');
        $plugin = DataKeeper::$plugin;
        $last = $plugin->measurements->last();
        $history = $plugin->measurements->history();

        $variables = [
            'measured' => $last !== null,
            'limits' => $plugin->measurements->limits(),
            'history' => Growth::byMonth($history),
            'conditions' => $plugin->notifications->conditions(),
            'canMeasure' => Craft::$app->getUser()->checkPermission(DataKeeper::PERMISSION_MEASURE),
            'canCleanup' => Craft::$app->getUser()->checkPermission(DataKeeper::PERMISSION_CLEANUP),
            'levels' => [],
        ];

        if ($last) {
            $variables += $last;
            $variables['growth'] = $plugin->measurements->growth($last);
            foreach ($last['figures'] as $key => $value) {
                $variables['levels'][$key] = Levels::forValue($variables['limits'][$key] ?? null, $value);
            }
            $user = Craft::$app->getUser()->getIdentity();
            $variables['topRevisions'] = array_map(function(array $row) use ($user) {
                $element = Craft::$app->getElements()->getElementById($row['canonicalId']);
                if ($element && !Craft::$app->getElements()->canView($element, $user)) {
                    // an element this person may not see: no title, no link
                    return ['title' => null, 'url' => null] + $row;
                }
                // the stored title can be empty (e.g. an entry type without a title field): ask the element
                $row['title'] = $row['title'] ?: ($element ? (string)$element : null);
                return $row + ['url' => $element?->getCpEditUrl()];
            }, $last['topRevisions']);
            $variables['tableNotes'] = [];
            foreach ($last['tables'] as $table) {
                $variables['tableNotes'][$table['name']] = $plugin->measurements->tableNote($table['name']);
            }
        }

        return $this->renderTemplate('data-keeper/index', $variables);
    }

    public function actionMeasure(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(DataKeeper::PERMISSION_MEASURE);
        DataKeeper::$plugin->measurements->measure();
        $this->setSuccessFlash(DataKeeper::t('Measured.'));
        return $this->redirect('data-keeper');
    }

    /**
     * Shows how many saved versions of an element would go, before anything is deleted (`?keep=` changes how many of
     * the newest stay). The deletion itself is a POST (`clear-versions`).
     */
    public function actionVersions(int $canonicalId): Response
    {
        $this->requirePermission(DataKeeper::PERMISSION_CLEANUP);
        $plugin = DataKeeper::$plugin;
        $element = $this->elementToClear($canonicalId);
        $total = $plugin->versions->count($canonicalId);
        if (!$element && $total === 0) {
            throw new NotFoundHttpException('Element not found.');
        }
        $settings = $plugin->getSettings();
        $request = $this->request;
        $submitted = $request->getQueryParam('keep') !== null;
        $keep = max(0, (int)$request->getQueryParam('keep', $settings->revisionsKeep));
        $keepFirst = $submitted ? (bool)$request->getQueryParam('keepFirst') : $settings->revisionsKeepFirst;
        $days = max(0, (int)$request->getQueryParam('days', $settings->revisionsMode === 'age' ? $settings->revisionsMaxAgeDays : 0));

        return $this->renderTemplate('data-keeper/versions', [
            'element' => $element,
            'canonicalId' => $canonicalId,
            'total' => $total,
            'keep' => $keep,
            'keepFirst' => $keepFirst,
            'days' => $days,
            'toDelete' => $plugin->versions->excess($canonicalId, $keep, $keepFirst, $days),
        ]);
    }

    public function actionClearVersions(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(DataKeeper::PERMISSION_CLEANUP);
        $canonicalId = (int)$this->request->getRequiredBodyParam('canonicalId');
        $this->elementToClear($canonicalId);
        $keep = max(0, (int)$this->request->getRequiredBodyParam('keep'));
        $keepFirst = (bool)$this->request->getBodyParam('keepFirst');
        $days = max(0, (int)$this->request->getBodyParam('days', 0));
        // the number the person saw and confirmed; if it changed in between, nothing is deleted
        $confirmed = (int)$this->request->getRequiredBodyParam('confirmedCount');
        $versions = DataKeeper::$plugin->versions;

        if ($versions->excess($canonicalId, $keep, $keepFirst, $days) !== $confirmed) {
            $this->setFailFlash(DataKeeper::t('The number of saved versions changed in the meantime. Please check it again.'));
            return $this->redirect("data-keeper/versions/$canonicalId?keep=$keep&keepFirst=" . (int)$keepFirst . "&days=$days");
        }

        $deleted = $versions->clear($canonicalId, $keep, $keepFirst, $days);
        Craft::info("Cleared $deleted saved versions of element $canonicalId (kept the newest $keep" . ($keepFirst ? ' and the first' : '') . ($days ? ", only older than $days days" : '') . ').', __METHOD__);
        $this->setSuccessFlash(DataKeeper::t('{n} saved versions were deleted. Measure again to update the figures.', ['n' => $deleted]));
        return $this->redirect('data-keeper');
    }

    /**
     * The element whose versions are cleared. The permission of this plugin is not enough: a person must also be
     * allowed to see and save the element itself (an element that is not found, e.g. in the trash, is for admins).
     */
    private function elementToClear(int $canonicalId): ?\craft\base\ElementInterface
    {
        $elements = Craft::$app->getElements();
        $user = Craft::$app->getUser()->getIdentity();
        $element = $elements->getElementById($canonicalId);
        if ($element) {
            if (!$elements->canView($element, $user) || !$elements->canSave($element, $user)) {
                throw new ForbiddenHttpException('You are not allowed to change this element.');
            }
        } elseif (!$user->admin) {
            throw new NotFoundHttpException('Element not found.');
        }
        return $element;
    }

    /** Sends a test message through the switched-on channels (admins). */
    public function actionTestNotification(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        $results = DataKeeper::$plugin->notifications->sendTest();
        if (!$results) {
            $this->setFailFlash(DataKeeper::t('No channel for pushed messages is switched on (e-mail or webhook).'));
        } else {
            $failed = array_filter($results, fn($r) => $r !== true);
            if ($failed) {
                $this->setFailFlash(DataKeeper::t('Test message failed: {errors}', [
                    'errors' => implode('; ', array_map(fn($c, $e) => "$c: $e", array_keys($failed), $failed)),
                ]));
            } else {
                $this->setSuccessFlash(DataKeeper::t('Test message sent via {channels}.', ['channels' => implode(', ', array_keys($results))]));
            }
        }
        return $this->redirectToPostedUrl();
    }
}
