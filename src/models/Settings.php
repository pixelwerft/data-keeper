<?php

namespace pixelwerft\datakeeper\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use pixelwerft\datakeeper\cleanup\TableRule;
use pixelwerft\datakeeper\helpers\NavPosition;

/**
 * Plugin settings. Everything that deletes or notifies is off by default.
 *
 * All settings can be overridden per environment in `config/data-keeper.php`.
 */
class Settings extends Model
{
    // Control panel
    // -------------------------------------------------------------------------

    /** @var string Where the section sits in the sidebar: `secondLast` (second from the bottom), `last` or `craft` (where Craft puts plugins). */
    public string $navPosition = NavPosition::SECOND_LAST;

    // Measuring
    // -------------------------------------------------------------------------

    /** @var array<string, array{warn?: int|float, alert?: int|float}> Limits per key figure; missing figures use the defaults. */
    public array $limits = [];

    /** @var int How many days of daily key figures are kept. */
    public int $historyDays = 400;

    /** @var array<string, string> Own notes for the table list, e.g. `['myplugin_log' => 'Request log']`. */
    public array $tableNotes = [];

    // Retention cleanup – every rule is off until it is switched on
    // -------------------------------------------------------------------------

    public bool $revisionsEnabled = false;
    /** @var string `count`: keep only the newest N versions of every entry; `age`: remove versions older than `revisionsMaxAgeDays`. */
    public string $revisionsMode = 'count';
    /** @var int Saved versions always kept per entry (the newest ones), in both modes. */
    public int $revisionsKeep = 20;
    /** @var bool Also keep the very first version of every entry, in both modes. */
    public bool $revisionsKeepFirst = false;
    /** @var int In mode `age`: versions saved more than this many days ago are removed. */
    public int $revisionsMaxAgeDays = 365;

    public bool $backupsEnabled = false;
    /** @var int Database backups kept (the newest ones). */
    public int $backupsKeep = 10;

    /** @var bool Offer «Clear» on the «Logs» card (removes all log files, after a confirmation). */
    public bool $logsClearable = true;

    public bool $logsEnabled = false;
    /** @var int Log files older than this many days are removed. */
    public int $logsKeepDays = 30;
    /**
     * @var string[]|string Log files that are never removed, neither by the rule nor by «Clear» on the card: file
     * names, `*` for any characters (`notification-digest.log`, `formie-*.log`). A list, or a text with one name per
     * line. The rotated copies of a kept file (`….log.1`) are kept too.
     */
    public array|string $logsKeep = [];

    public bool $changeRecordsEnabled = false;
    /** @var int Change records (which field changed when) older than this many days are removed. */
    public int $changeRecordsKeepDays = 180;

    /** @var array<int, array{table?: string, column?: string, keepDays?: int|string, enabled?: bool|string}> Rows older than `keepDays` in a table outside Craft's own. */
    public array $customRules = [];

    // Notifications – every topic is off until it is switched on
    // -------------------------------------------------------------------------

    /** @var bool Topic: a key figure reached the «keep an eye on it» or «act now» level. */
    public bool $notifyLevels = false;
    /** @var string The lowest level that counts for the topic above: `warn` or `alert`. */
    public string $minLevel = 'warn';
    /** @var bool Topic: no measurement for a while. */
    public bool $notifyStale = false;
    public int $staleAfterDays = 3;
    /** @var bool Topic: a cleanup run finished or failed. */
    public bool $notifyCleanup = false;
    /** @var bool Topic: a rule could remove many old records. */
    public bool $notifyBacklog = false;
    public int $backlogItems = 1000;
    /** @var bool Topic: Craft's queue is in trouble – jobs failed, a job hangs, or nothing is being worked off. */
    public bool $notifyQueue = false;
    /**
     * @var int A job that has been waiting this many minutes while none is running means that the queue is not being
     * worked off. Used wherever the state of the queue is shown, whether the topic above is on or not.
     */
    public int $queueWaitMinutes = 15;

    /** @var int A condition that stays is sent again after this many days (0: only once). */
    public int $remindAfterDays = 7;

    // Channels
    // -------------------------------------------------------------------------

    /** @var bool Show a notice in the control panel for users who may open the plugin. */
    public bool $channelCp = false;
    public bool $channelEmail = false;
    /** @var string Comma-separated addresses. */
    public string $emailRecipients = '';
    public bool $channelWebhook = false;
    public string $webhookUrl = '';
    /** @var string Signs the webhook body (HMAC SHA-256, header `X-Data-Keeper-Signature`). Environment variables allowed. */
    public string $webhookSecret = '';
    /** @var string Token for the status endpoint; empty switches the endpoint off. Environment variables allowed. */
    public string $statusToken = '';

    // Remote monitoring – off until switched on
    // -------------------------------------------------------------------------

    /**
     * @var bool|string|null Let a monitoring tool outside the site read what the site runs on (versions, waiting
     * updates) and the key figures with their status: `/actions/data-keeper/remote`, needs the key of the site. Meant
     * for `config/data-keeper.php`, where it decides for good. Null (not set): the switch on the «Information» tab
     * decides (see `Remote::enabled()`). A string is allowed so that an environment variable can be named; only a
     * clear yes counts.
     */
    public bool|string|null $allowRemoteMonitor = null;

    /**
     * @var bool Also answer remote queries over plain HTTP. Only for a site behind a proxy that ends HTTPS and does
     * not tell Craft so; set it in `config/data-keeper.php`. While dev mode is on, HTTP is allowed anyway.
     */
    public bool $remoteAllowHttp = false;

    /**
     * The limits of a key figure: the defaults overridden by the settings.
     *
     * @param array{warn: int|float, alert: int|float} $default
     * @return array{warn: int|float, alert: int|float}
     */
    public function limitFor(string $figure, array $default): array
    {
        $own = $this->limits[$figure] ?? [];
        return [
            'warn' => is_numeric($own['warn'] ?? null) ? $own['warn'] + 0 : $default['warn'],
            'alert' => is_numeric($own['alert'] ?? null) ? $own['alert'] + 0 : $default['alert'],
        ];
    }

    /**
     * Whether a value means «remote monitoring is allowed». Only a clear yes counts: `true`, `'1'`, `'true'`, `'on'`,
     * `'yes'`, or an environment variable with one of these. Anything else – `'false'`, an empty or unknown value –
     * means no, so a typing mistake in a config file cannot open the address.
     */
    public static function isYes(mixed $value): bool
    {
        return App::parseBooleanEnv($value) === true;
    }

    /** @return string[] */
    public function recipients(): array
    {
        $list = array_map('trim', explode(',', App::parseEnv($this->emailRecipients)));
        return array_values(array_filter($list, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    }

    public function token(): string
    {
        return (string)App::parseEnv($this->statusToken);
    }

    public function secret(): string
    {
        return (string)App::parseEnv($this->webhookSecret);
    }

    public function webhook(): string
    {
        return (string)App::parseEnv($this->webhookUrl);
    }

    /** The custom rules as normalised rows (empty rows dropped). */
    public function customRuleRows(): array
    {
        $rows = [];
        foreach ($this->customRules as $row) {
            $table = trim((string)($row['table'] ?? ''));
            $column = trim((string)($row['column'] ?? ''));
            if ($table === '' && $column === '') {
                continue;
            }
            $rows[] = [
                'table' => $table,
                'column' => $column,
                'keepDays' => (int)($row['keepDays'] ?? 0),
                'enabled' => filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        return $rows;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['navPosition'], 'in', 'range' => NavPosition::ALL],
            [['historyDays'], 'integer', 'min' => 30, 'max' => 3650],
            [['revisionsKeep', 'backupsKeep'], 'integer', 'min' => 1, 'max' => 100000],
            [['revisionsMaxAgeDays'], 'integer', 'min' => 30, 'max' => 3650],
            [['revisionsMode'], 'in', 'range' => ['count', 'age']],
            [['logsKeepDays'], 'integer', 'min' => 1, 'max' => 3650],
            [['logsKeep'], 'filter', 'filter' => fn($value) => self::namesFrom($value)],
            [['logsKeep'], 'validateFileNames'],
            [['changeRecordsKeepDays'], 'integer', 'min' => 30, 'max' => 3650],
            [['staleAfterDays'], 'integer', 'min' => 1, 'max' => 365],
            [['backlogItems'], 'integer', 'min' => 1],
            [['queueWaitMinutes'], 'integer', 'min' => 1, 'max' => 1440],
            [['remindAfterDays'], 'integer', 'min' => 0, 'max' => 365],
            [['minLevel'], 'in', 'range' => ['warn', 'alert']],
            [['emailRecipients', 'webhookUrl', 'webhookSecret', 'statusToken'], 'string', 'max' => 500],
            [['webhookUrl'], 'validateWebhook'],
            [['statusToken'], 'validateToken'],
            [['customRules'], 'validateCustomRules'],
        ];
    }

    /**
     * The log files to keep as a clean list of patterns.
     *
     * @return string[]
     */
    public function logsKept(): array
    {
        return self::namesFrom($this->logsKeep);
    }

    /** Whether a log file is one to keep: it matches a pattern, or is a rotated copy (`name.1`) of a file that does. */
    public function keepsLog(string $fileName): bool
    {
        foreach ($this->logsKept() as $pattern) {
            if (fnmatch($pattern, $fileName, FNM_CASEFOLD) || fnmatch($pattern . '.*', $fileName, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A list of file names from what was entered: a list, or a text with one name per line (commas work too).
     *
     * @return string[]
     */
    private static function namesFrom(mixed $value): array
    {
        $names = is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string)$value) ?: []);
        $names = array_map(fn($name) => is_string($name) ? trim($name) : '', $names);
        return array_values(array_unique(array_filter($names, fn(string $name) => $name !== '')));
    }

    public function validateFileNames(string $attribute): void
    {
        foreach (self::namesFrom($this->$attribute) as $name) {
            if (strlen($name) > 100 || !preg_match('/^[A-Za-z0-9._*?-]+$/', $name) || !preg_match('/[A-Za-z0-9]/', $name)) {
                $this->addError($attribute, Craft::t('data-keeper', '«{name}» is not a file name. Use letters, digits, dots, hyphens and underscores, and * for any characters; no folders.', ['name' => $name]));
            }
        }
    }

    public function validateWebhook(string $attribute): void
    {
        $url = $this->webhook();
        if ($url !== '' && !preg_match('#^https?://[^\s/]+#i', $url)) {
            $this->addError($attribute, Craft::t('data-keeper', 'The webhook URL must start with http:// or https://.'));
        }
    }

    public function validateToken(string $attribute): void
    {
        $token = $this->token();
        if ($token !== '' && strlen($token) < 24) {
            $this->addError($attribute, Craft::t('data-keeper', 'The status token must have at least 24 characters.'));
        }
    }

    public function validateCustomRules(string $attribute): void
    {
        foreach ($this->customRuleRows() as $i => $row) {
            $error = TableRule::validateConfig($row);
            if ($error !== null) {
                $this->addError($attribute, Craft::t('data-keeper', 'Custom rule {n}: {error}', ['n' => $i + 1, 'error' => $error]));
            }
        }
    }
}
