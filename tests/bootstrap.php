<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Default to the test environment before the test application boots Dotenv,
// so `tests/TestApplication/.env.test` is the one that wins.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'test';

// Loads `vendor/sylius/test-application/.env`, then this plugin's
// `tests/TestApplication/.env`, and points the cache/log dirs at `var/`.
require dirname(__DIR__) . '/vendor/sylius/test-application/config/bootstrap.php';
