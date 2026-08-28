<?php

declare(strict_types=1);

use Kode\Framework\Application;

require __DIR__ . '/../vendor/autoload.php';

$app = Application::make(basePath: dirname(__DIR__));

$app->http()->run();
