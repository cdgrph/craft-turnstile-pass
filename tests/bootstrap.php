<?php
declare(strict_types=1);

// Load Composer dependencies and the project PSR-4 autoloader.
require __DIR__ . '/../vendor/autoload.php';
// Yii is not Composer-autoloadable; validators resolve classes through Yii::createObject.
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
// Craft extends Yii; functional tests require it for plugin instantiation.
require __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';
