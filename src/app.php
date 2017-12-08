<?php

namespace Cloudstek\InfluxStatusCake;

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

$env = new Dotenv();
$env->load(__DIR__.'/../.env');

$app = new Application('influx-statuscake', 'v1.0.0');
$app->add(new Command\PerformanceCommand());
$app->run();