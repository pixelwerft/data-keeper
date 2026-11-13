<?php

namespace pixelwerft\datakeeper\controllers;

use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Levels;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * The status endpoint for monitoring tools: `/actions/data-keeper/status`.
 *
 * Off until a token is set. The token goes in the `Authorization: Bearer …` header (or, for tools that can only call a
 * URL, in `?token=`). Answers with the stored state only – it never measures, so calling it is cheap. With
 * `?fail=warn` or `?fail=alert` the HTTP status is 503 from that level on, so a plain «is the URL up» monitor can alert.
 */
class StatusController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function beforeAction($action): bool
    {
        // a read-only JSON endpoint called by tools, not a form
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $token = DataKeeper::$plugin->getSettings()->token();
        if ($token === '') {
            throw new NotFoundHttpException();
        }

        $header = (string)$this->request->getHeaders()->get('Authorization', '');
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : (string)$this->request->getQueryParam('token', '');
        if (!hash_equals($token, $given)) {
            throw new UnauthorizedHttpException('Invalid token.');
        }

        $status = DataKeeper::$plugin->notifications->status();
        $fail = (string)$this->request->getQueryParam('fail', '');
        $response = $this->asJson($status);
        if (in_array($fail, ['warn', 'alert'], true) && Levels::atLeast($status['status'], $fail)) {
            $response->setStatusCode(503);
        }
        $response->getHeaders()->set('Cache-Control', 'no-store');
        return $response;
    }
}
