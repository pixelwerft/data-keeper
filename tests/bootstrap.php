<?php

// Pure unit tests: they need the plugin's classes only, not a Craft install.
// Set COMPOSER_AUTOLOAD to another autoloader (e.g. a Craft project's vendor/autoload.php) if `composer install` was not run here.
require getenv('COMPOSER_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
