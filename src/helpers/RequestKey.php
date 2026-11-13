<?php

namespace pixelwerft\datakeeper\helpers;

use Craft;
use craft\web\Request;

/**
 * The key (or token) a tool sends with a request to one of the plugin's two addresses for tools.
 *
 * The key for remote queries is read from a header only – `X-Data-Keeper-Key: <key>` or `Authorization: Bearer
 * <key>` – never from the address, which ends up in the logs of web servers and proxies. The token of the status
 * endpoint, which says far less, may also come as `?statusToken=` for tools that can only call a URL.
 *
 * Craft writes the request with every warning and error it logs (and with everything while dev mode is on), including
 * `$_SERVER` and so the headers; it masks values by the name of their key, and «authorization» is not on its list. So
 * the key is taken out of `$_SERVER` as early as possible: what is logged later in the request no longer holds it.
 */
final class RequestKey
{
    private const HEADER = 'X-Data-Keeper-Key';
    private const SERVER_KEYS = ['HTTP_X_DATA_KEEPER_KEY', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'];

    /**
     * `key=…`, `statusToken=…` or `token=…` as a query parameter, also in its array form (`key[]=…`), plain or
     * URL-encoded. (`token` is the name the status endpoint was documented with before 1.5; Craft keeps it for itself
     * and refuses such a request, but would still write it to the log.)
     */
    private const IN_ADDRESS = '/((?:^|[?&])(?:key|statusToken|token)(?:\[[^\]=&]*\]|%5B[^=&]*%5D)*=)[^&#\s]*/i';

    private const STATUS_PARAM = 'statusToken';

    private static ?string $key = null;
    private static string $fromAddress = '';

    /**
     * Whether the current request asks one of the addresses for tools (`data-keeper/remote`, `data-keeper/status`).
     */
    public static function isForTools(): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request || !$request->getIsActionRequest()) {
            return false;
        }
        $segments = $request->getActionSegments() ?? [];
        return ($segments[0] ?? null) === 'data-keeper' && in_array($segments[1] ?? null, ['remote', 'status'], true);
    }

    /**
     * Reads the key from the request and removes it from `$_SERVER`. Safe to call more than once.
     */
    public static function take(): void
    {
        if (self::$key !== null) {
            return;
        }
        $request = Craft::$app->getRequest();
        $own = $request instanceof Request ? $request->getHeaders()->get(self::HEADER) : null;
        $authorization = $request instanceof Request ? $request->getHeaders()->get('Authorization') : null;

        self::$key = '';
        if (is_string($own) && trim($own) !== '') {
            self::$key = trim($own);
        } elseif (is_string($authorization) && preg_match('/^Bearer\s+(\S+)\s*$/i', $authorization, $match)) {
            self::$key = $match[1];
        }

        foreach (self::SERVER_KEYS as $name) {
            unset($_SERVER[$name]);
        }
        // a key or token in the address (`?key=`, `?statusToken=`) is in several entries of `$_SERVER` – the query
        // string, the request URI, what a rewrite left behind: masked in all of them
        foreach ($_SERVER as $name => $value) {
            if (is_string($value) && preg_match(self::IN_ADDRESS, $value)) {
                $_SERVER[$name] = preg_replace(self::IN_ADDRESS, '$1***', $value);
            }
        }
        // … and in `$_GET`, which Craft only masks when the value is a plain string (not for `key[]=…`). The status
        // token may come in the address, so it is kept here first.
        self::$fromAddress = is_string($_GET[self::STATUS_PARAM] ?? null) ? $_GET[self::STATUS_PARAM] : '';
        foreach (['key', self::STATUS_PARAM] as $name) {
            if (isset($_GET[$name])) {
                $_GET[$name] = '***';
            }
        }
    }

    /** The key the tool sent in a header (empty: none). */
    public static function get(): string
    {
        self::take();
        return (string)self::$key;
    }

    /** The token the tool put in the address as `?statusToken=` (empty: none). Only the status endpoint takes it. */
    public static function fromAddress(): string
    {
        self::take();
        return self::$fromAddress;
    }
}
