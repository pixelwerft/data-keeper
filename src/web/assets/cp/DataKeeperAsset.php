<?php

namespace pixelwerft\datakeeper\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The few styles of the Data Keeper pages.
 */
class DataKeeperAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['data-keeper.css'];

        parent::init();
    }
}
