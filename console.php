#!/usr/bin/env php
<?php

if (!is_dir(__DIR__ . "/vendor")) {
  shell_exec("composer install --no-dev  -o -d " . __DIR__);
}

require __DIR__ . '/vendor/autoload.php';

use App\Command\TestCommand;
use App\Command\GnssCommand;
use App\Command\MixerCommand;
use App\Command\ModBusCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

// Sup .env vars.
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

// Symfony app.
$app = new Application('Console App', 'v0.1.0');
$app->add(new ModBusCommand());
$app->add(new MixerCommand());
$app->add(new GnssCommand());
$app->add(new TestCommand());
if (!empty($_ENV['APP_TEMPLATE'])) {
  $app->setDefaultCommand($_ENV['APP_TEMPLATE'], TRUE);
}
// Run.
$app->run();
