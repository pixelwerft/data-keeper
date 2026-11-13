<?php

namespace pixelwerft\datakeeper\controllers;

use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Remote queries: `/actions/data-keeper/remote` answers with the stored measurement as JSON.
 *
 * Off (404) until «Allow remote queries» is switched on. The key of the site goes in the `Authorization: Bearer …`
 * header (or, for tools that can only call a URL, in `?key=`); without the right key the answer is 401. It only
 * reads what was stored – it never measures, so calling it is cheap. `?history=daily` gives the course day by day.
 */
class RemoteController extends Controller
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
        $remote = DataKeeper::$plugin->remote;
        if (!$remote->enabled()) {
            throw new NotFoundHttpException();
        }

        $header = (string)$this->request->getHeaders()->get('Authorization', '');
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : (string)$this->request->getQueryParam('key', '');
        if (!$remote->matches(trim($given))) {
            throw new UnauthorizedHttpException('Invalid key.');
        }

        $response = $this->asJson($remote->data($this->request->getQueryParam('history') === 'daily'));
        $response->getHeaders()->set('Cache-Control', 'no-store');
        return $response;
    }
}
