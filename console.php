<?php

/**
 * @file
 * Console RUN.
 */

require __DIR__ . '/vendor/autoload.php';

use App\Command\GnssCommand;
use App\Command\MixerCommand;
use App\Command\ModBusCommand;
use App\Command\ScaleCommand;
use App\Command\TestCommand;
use App\Command\UartHealthCommand;
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
$app->add(new ScaleCommand());
$app->add(new UartHealthCommand());
if (!empty($_ENV['APP_TEMPLATE'])) {
  $app->setDefaultCommand($_ENV['APP_TEMPLATE'], TRUE);
}
// Run.
$app->run();
