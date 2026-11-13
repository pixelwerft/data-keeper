<?php

namespace pixelwerft\datakeeper;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp as CpVariable;
use craft\web\UrlManager;
use pixelwerft\datakeeper\helpers\NavPosition;
use pixelwerft\datakeeper\models\Settings;
use pixelwerft\datakeeper\services\Cleanup;
use pixelwerft\datakeeper\services\Maintenance;
use pixelwerft\datakeeper\services\Measurements;
use pixelwerft\datakeeper\services\Notifications;
use pixelwerft\datakeeper\services\Remote;
use pixelwerft\datakeeper\services\Store;
use pixelwerft\datakeeper\services\Versions;
use yii\base\Event;

/**
 * Data Keeper: how much a site stores and how fast it grows, with opt-in retention cleanup, notifications and a
 * status endpoint.
 *
 * @property-read Measurements $measurements
 * @property-read Cleanup $cleanup
 * @property-read Versions $versions
 * @property-read Notifications $notifications
 * @property-read Store $store
 * @property-read Maintenance $maintenance
 * @property-read Remote $remote
 * @method Settings getSettings()
 */
class DataKeeper extends Plugin
{
    public const PERMISSION_MEASURE = 'data-keeper:measure';
    public const PERMISSION_CLEANUP = 'data-keeper:cleanup';

    public static DataKeeper $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'store' => Store::class,
                'measurements' => Measurements::class,
                'cleanup' => Cleanup::class,
                'versions' => Versions::class,
                'notifications' => Notifications::class,
                'maintenance' => Maintenance::class,
                'remote' => Remote::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'pixelwerft\\datakeeper\\console\\controllers';
        }

        $this->registerUrlRules();
        $this->registerPermissions();
        $this->registerCpAlert();
        $this->registerNavPosition();
    }

    public static function t(string $message, array $params = []): string
    {
        return Craft::t('data-keeper', $message, $params);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();
        $item['subnav'] = [
            'overview' => ['label' => self::t('Overview'), 'url' => 'data-keeper'],
        ];
        if ($user->checkPermission(self::PERMISSION_CLEANUP)) {
            $item['subnav']['cleanup'] = ['label' => self::t('Cleanup'), 'url' => 'data-keeper/cleanup'];
        }
        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $item['subnav']['settings'] = ['label' => self::t('Settings'), 'url' => 'settings/plugins/data-keeper'];
        }
        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function afterUninstall(): void
    {
        // the plugin keeps nothing in the database; remove its file too, so uninstalling leaves nothing behind
        $folder = dirname($this->store->path());
        if (is_dir($folder)) {
            FileHelper::removeDirectory($folder);
        }
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $overridden = array_keys(Craft::$app->getConfig()->getConfigFromFile($this->id));
        return Craft::$app->getView()->renderTemplate('data-keeper/settings', [
            'settings' => $this->getSettings(),
            'figures' => $this->measurements->limits(),
            'overridden' => $overridden,
            'statusUrl' => UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/data-keeper/status'),
            'remoteUrl' => $this->remote->url(),
        ]);
    }

    private function registerUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['data-keeper'] = 'data-keeper/default/index';
            $event->rules['data-keeper/cleanup'] = 'data-keeper/cleanup/index';
            $event->rules['data-keeper/versions/<canonicalId:\d+>'] = 'data-keeper/default/versions';
            $event->rules['data-keeper/action/<id:[a-z-]+>'] = 'data-keeper/maintenance/index';
            $event->rules['data-keeper/backups'] = 'data-keeper/maintenance/backups';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => self::t('Data Keeper'),
                'permissions' => [
                    self::PERMISSION_MEASURE => ['label' => self::t('Measure the site')],
                    self::PERMISSION_CLEANUP => ['label' => self::t('See what a cleanup would remove, run it and clear saved versions')],
                ],
            ];
        });
    }

    private function registerCpAlert(): void
    {
        Event::on(Cp::class, Cp::EVENT_REGISTER_ALERTS, function(RegisterCpAlertsEvent $event) {
            $user = Craft::$app->getUser();
            if (!$user->checkPermission('accessPlugin-' . $this->id)) {
                return;
            }
            $alert = $this->notifications->cpAlert();
            if ($alert !== null) {
                $event->alerts[] = $alert;
            }
        });
    }

    /**
     * Places the section in the sidebar as the setting «Position in the sidebar» says: second from the bottom (the
     * default), at the very bottom, or where Craft puts plugin sections. The handler is registered once the
     * application is ready, so it runs after the ones of other plugins and also counts the items they add at the end.
     * `hasCpSection` stays on, so Craft still builds the item and checks the access permission.
     */
    private function registerNavPosition(): void
    {
        Craft::$app->onInit(function() {
            Event::on(CpVariable::class, CpVariable::EVENT_REGISTER_CP_NAV_ITEMS, function(RegisterCpNavItemsEvent $event) {
                $event->navItems = NavPosition::place($event->navItems, $this->id, $this->getSettings()->navPosition);
            });
        });
    }
}
