<?php

namespace pixelwerft\datakeeper\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use pixelwerft\datakeeper\DataKeeper;

/**
 * Optional queue job: runs the retention cleanup (the rules that are switched on) and measures afterwards.
 *
 * With `apply` false it is a dry run: nothing is removed, the site is only measured. Started from the cleanup page
 * (in the background, so a web request does not time out) or by `craft data-keeper/run --queue`.
 */
class RunJob extends BaseJob
{
    /** @var bool false: dry run, nothing is removed */
    public bool $apply = false;

    /** @var string who started it: `cp`, `console` */
    public string $source = 'console';

    /** @var array<string, int>|null per rule id, what a person confirmed; a rule that would now remove more is skipped */
    public ?array $confirmed = null;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = DataKeeper::$plugin;

        $plugin->cleanup->run($this->apply, null, $this->source, function(string $rule, int $done, int $total) use ($queue) {
            if ($total > 0) {
                $this->setProgress($queue, 0.9 * $done / $total, $rule);
            }
        }, $this->confirmed);

        $this->setProgress($queue, 0.95, 'measuring');
        $plugin->measurements->measure();
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('data-keeper', $this->apply ? 'Data Keeper: cleanup' : 'Data Keeper: measuring');
    }
}
