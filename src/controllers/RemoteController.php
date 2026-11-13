<?php

namespace pixelwerft\datakeeper\controllers;

use craft\web\Controller;
use pixelwerft\datakeeper\DataKeeper;
use pixelwerft\datakeeper\helpers\RequestKey;
use yii\web\Response;

/**
 * Remote queries: `/actions/data-keeper/remote` answers with the last measurement and what the site runs on as JSON.
 *
 * The answer is always JSON, also when something is wrong, because the one who asks is a tool and not a browser:
 *
 * - 405 `method_not_allowed` for anything but GET,
 * - 403 `disabled` while «Allow remote queries» is switched off,
 * - 403 `https_required` over plain HTTP (the key and the answer would travel unprotected),
 * - 401 `unauthorized` without the key of the site or with a wrong one,
 * - 200 with the figures otherwise.
 *
 * The key is read from a header only – `X-Data-Keeper-Key: <key>` or `Authorization: Bearer <key>` – never from the
 * address, which ends up in the logs of web servers and proxies. The endpoint only reads what was stored – it never
 * measures. `?details=1` adds everything else the overview shows, `?history=daily` the course day by day in it.
 *
 * Nothing here throws on purpose: Craft logs an exception together with the whole request, and an anonymous caller
 * must not be able to fill the log (or get a trace while dev mode is on).
 */
class RemoteController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function beforeAction($action): bool
    {
        // out of the request before anything can write it to a log
        RequestKey::take();
        // a read-only JSON endpoint called by tools, not a form
        $this->enableCsrfValidation = false;
        // whatever happens from here on is answered as JSON, also an error Craft reports itself
        $this->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $remote = DataKeeper::$plugin->remote;

        if (!in_array($this->request->getMethod(), ['GET', 'HEAD'], true)) {
            $response = $this->problem(405, 'method_not_allowed', 'Only GET is allowed.');
            $response->getHeaders()->set('Allow', 'GET, HEAD');
            return $response;
        }
        if (!$remote->enabled()) {
            return $this->problem(403, 'disabled', 'Remote queries are not allowed on this site.');
        }
        if (!$this->request->getIsSecureConnection() && !$remote->httpAllowed()) {
            return $this->problem(403, 'https_required', 'Remote queries are only answered over HTTPS.');
        }

        $given = RequestKey::get();
        if (!$remote->matches($given)) {
            $response = $this->problem(401, 'unauthorized', $given === ''
                ? 'The key of the site is missing. Send it in the header «X-Data-Keeper-Key» or as «Authorization: Bearer <key>».'
                : 'This is not the key of the site.');
            $response->getHeaders()->set('WWW-Authenticate', 'Bearer');
            return $response;
        }

        // only `1`/`daily` as plain strings mean something; anything else (an array, for example) is ignored
        $query = $this->request->getQueryParams();
        return $this->answer($remote->data(
            ($query['details'] ?? null) === '1',
            ($query['history'] ?? null) === 'daily',
        ));
    }

    /** What went wrong, as JSON with the matching HTTP status. The messages are English: a tool reads them. */
    private function problem(int $status, string $error, string $message): Response
    {
        $response = $this->answer(['error' => $error, 'message' => $message, 'status' => $status]);
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
        $headers->set('Vary', 'Authorization, X-Data-Keeper-Key');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
