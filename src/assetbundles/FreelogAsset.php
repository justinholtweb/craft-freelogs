<?php

namespace justinholtweb\freelog\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class FreelogAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@justinholtweb/freelog/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/freelog.css',
        ];

        $this->js = [
            'js/freelog.js',
        ];

        parent::init();
    }
}
