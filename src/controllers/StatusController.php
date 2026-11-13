<?php

namespace pixelwerft\datakeeper\controllers;

use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\Levels;
use pixelwerft\datakeeper\helpers\RequestKey;
use yii\web\Response;

/**
 * The status endpoint for monitoring tools: `/actions/data-keeper/status`.
 *
 * Off until a token is set. The token goes in a header – `X-Data-Keeper-Key: <token>` or `Authorization: Bearer
 * <token>` – or, for tools that can only call a URL, in `?statusToken=`. (Not `?token=`: Craft keeps that name for
 * its own tokens and refuses the request before it gets here.) Answers with the stored state only – it never
 * measures, so calling it is cheap. With `?fail=warn` or `?fail=alert` the HTTP status is 503 from that level on, so
 * a plain «is the URL up» monitor can alert.
 *
 * The answer is always JSON: 404 `not_found` while no token is set, 401 `unauthorized` without the right one. Nothing
 * here throws on purpose: Craft logs an exception together with the whole request, and an anonymous caller must not
 * be able to fill the log.
 */
class StatusController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function beforeAction($action): bool
    {
        // out of the request before anything can write it to a log
        RequestKey::take();
        // a read-only JSON endpoint called by tools, not a form
        $this->enableCsrfValidation = false;
        $this->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $token = DataKeeper::$plugin->getSettings()->token();
        if ($token === '') {
            return $this->problem(404, 'not_found', 'Not found.');
        }

        // only plain strings mean something; anything else (an array, for example) is ignored
        $query = $this->request->getQueryParams();
        $given = RequestKey::get() ?: RequestKey::fromAddress();
        if ($given === '' || !hash_equals($token, $given)) {
            $response = $this->problem(401, 'unauthorized', 'The token is missing or wrong.');
            $response->getHeaders()->set('WWW-Authenticate', 'Bearer');
            return $response;
        }

        $status = DataKeeper::$plugin->notifications->status();
        $fail = is_string($query['fail'] ?? null) ? $query['fail'] : '';
        $response = $this->answer($status);
        if (in_array($fail, ['warn', 'alert'], true) && Levels::atLeast($status['status'], $fail)) {
            $response->setStatusCode(503);
        }
        return $response;
    }

    /** What went wrong, as JSON with the matching HTTP status. */
    private function problem(int $status, string $error, string $message): Response
    {
        $response = $this->answer(['error' => $error, 'message' => $message]);
        $response->setStatusCode($status);
        return $response;
    }

    /** JSON that no browser, proxy or search engine keeps. */
    private function answer(array $data): Response
    {
        $response = $this->asJson($data);
        $headers = $response->getHeaders();
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
