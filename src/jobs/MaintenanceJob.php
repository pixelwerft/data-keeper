<?php

namespace pixelwerft\datakeeper\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use pixelwerft\datakeeper\DataKeeper;

/**
 * Runs one action a person confirmed on its page (clear change records, empty the trash, clear the logs, optimize or
 * check the tables) in the background, so a web request does not time out, and measures afterwards.
 */
class MaintenanceJob extends BaseJob
{
    /** @var string the id of the action */
    public string $id = '';

    /** @var int|null what the person confirmed; an action that would now remove more is skipped */
    public ?int $confirmed = null;

    /** @var string who started it: `cp`, `console` */
    public string $source = 'cp';

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = DataKeeper::$plugin;
        if ($plugin->maintenance->action($this->id) === null) {
            return;
        }

        $plugin->maintenance->run($this->id, $this->confirmed, $this->source, function(int $done, int $total) use ($queue) {
            if ($total > 0) {
                $this->setProgress($queue, 0.9 * $done / $total);
            }
        });

        $this->setProgress($queue, 0.95);
        $plugin->measurements->measure();
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('data-keeper', 'Data Keeper: maintenance');
    }
}
