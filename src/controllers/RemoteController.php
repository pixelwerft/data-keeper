<?php

namespace pixelwerft\datakeeper\controllers;

use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use yii\web\Response;

/**
 * Remote queries: `/actions/data-keeper/remote` answers with the stored measurement as JSON.
 *
 * The answer is always JSON, also when something is wrong, because the one who asks is a tool and not a browser:
 *
 * - 403 `disabled` while «Allow remote queries» is switched off,
 * - 401 `unauthorized` without the key of the site or with a wrong one,
 * - 200 with the figures otherwise.
 *
 * The key goes in the `Authorization: Bearer …` header (or, for tools that can only call a URL, in `?key=`). The
 * endpoint only reads what was stored – it never measures. `?details=1` adds everything else the overview shows,
 * `?history=daily` the course day by day in it.
 */
class RemoteController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function beforeAction($action): bool
    {
        // a read-only JSON endpoint called by tools, not a form
        $this->enableCsrfValidation = false;
        // whatever happens from here on is answered as JSON, also an error Craft reports itself
        $this->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $remote = DataKeeper::$plugin->remote;
        if (!$remote->enabled()) {
            return $this->problem(403, 'disabled', 'Remote queries are not allowed on this site. Switch on «Allow remote queries» in the settings of Data Keeper.');
        }

        $header = (string)$this->request->getHeaders()->get('Authorization', '');
        $given = trim(str_starts_with($header, 'Bearer ') ? substr($header, 7) : (string)$this->request->getQueryParam('key', ''));
        if (!$remote->matches($given)) {
            $response = $this->problem(401, 'unauthorized', $given === ''
                ? 'The key of the site is missing. Send it as «Authorization: Bearer <key>» or as ?key=.'
                : 'This is not the key of the site.');
            $response->getHeaders()->set('WWW-Authenticate', 'Bearer');
            return $response;
        }

        return $this->answer($remote->data(
            (bool)$this->request->getQueryParam('details'),
            $this->request->getQueryParam('history') === 'daily',
        ));
    }

    /** What went wrong, as JSON with the matching HTTP status. The messages are English: a tool reads them. */
    private function problem(int $status, string $error, string $message): Response
    {
        $response = $this->answer(['error' => $error, 'message' => $message, 'status' => $status]);
        $response->setStatusCode($status);
        return $response;
    }

    private function answer(array $data): Response
    {
        $response = $this->asJson($data);
        $response->getHeaders()->set('Cache-Control', 'no-store');
        return $response;
    }
}
