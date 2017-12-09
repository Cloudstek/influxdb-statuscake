<?php

namespace Cloudstek\InfluxStatusCake;

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use GuzzleHttp\Client as HttpClient;
use InfluxDB\Client as InfluxClient;

$env = new Dotenv();
$env->load(__DIR__.'/../.env');

// Initialize HTTP client
$httpClient = new HttpClient([
    'base_uri' => 'https://app.statuscake.com/API/',
    'headers' => [
        'API' => getenv('STATUSCAKE_API'),
        'Username' => getenv('STATUSCAKE_USERNAME')
    ]
]);

// Initialize InfluxDB client
$influxClient = new InfluxClient(
    getenv('INFLUXDB_HOST') ?? '127.0.0.1',
    getenv('INFLUXDB_PORT'),
    getenv('INFLUXDB_USERNAME'),
    getenv('INFLUXDB_PASSWORD'),
    getenv('INFLUXDB_SSL') ?? false,
    getenv('INFLUXDB_VERIFY_SSL') ?? false
);

$influxDatabase = $influxClient->selectDB(getenv('INFLUXDB_DB'));

// Initialize log
$log = (new Logger('app'))
    ->pushHandler(new StreamHandler(__DIR__.'/../logs/app.log', Logger::WARNING));

// Initialize StatusCake service
$statusCake = new Service\StatusCake($httpClient, $log);

// Initialize app
$app = new Application('influx-statuscake', 'v1.0.0');
$app->add(new Command\PerformanceCommand($statusCake, $influxDatabase));
$app->add(new Command\UptimeCommand($statusCake, $influxDatabase));
$app->run();