<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Ensure APP_ENV is set to test before loading dotenv
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
