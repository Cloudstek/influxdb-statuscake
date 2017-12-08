<?php

namespace Cloudstek\InfluxStatusCake\Command;

use Psr\Log\LoggerInterface;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Cache\Simple\FilesystemCache;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

use InfluxDB\Client as InfluxClient;
use InfluxDB\Database;
use InfluxDB\Point;

/**
 * Performance data command
 */
class PerformanceCommand extends Command
{
    use LockableTrait;

    /**
     * HTTP Client
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * InfluxDB Client
     * @var InfluxClient
     */
    private $influxClient;

    /**
     * Logger
     * @var LoggerInterface
     */
    private $log;

    /**
     * Cache
     * @var CacheInterface
     */
    private $cache;

    /**
     * @inheritdoc
     */
    public function __construct(?ClientInterface $httpClient = null, ?InfluxClient $influxClient = null, ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        parent::__construct();

        // Create cache
        $this->cache = $cache ?? new FilesystemCache();

        // Create logger
        $this->log = $logger ?? (new Logger('performance'))->pushHandler(new StreamHandler(__DIR__.'/../../logs/performance.log', Logger::WARNING));

        // Initialize HTTP client
        $this->client = $httpClient ?? new HttpClient([
            'base_uri' => 'https://app.statuscake.com/API/',
            'headers' => [
                'API' => getenv('STATUSCAKE_API'),
                'Username' => getenv('STATUSCAKE_USERNAME')
            ]
        ]);

        // Initialize InfluxDB client
        $this->influxClient = $influxClient ?? new InfluxClient(
            getenv('INFLUXDB_HOST') ?? '127.0.0.1',
            getenv('INFLUXDB_PORT'),
            getenv('INFLUXDB_USERNAME'),
            getenv('INFLUXDB_PASSWORD'),
            getenv('INFLUXDB_SSL') ?? false,
            getenv('INFLUXDB_VERIFY_SSL') ?? false
        );
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('performance')
            ->setDescription('Store performance data from StatusCake');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Wait for previous command to finish
        $this->lock(null, true);

        // Get list of test IDs
        $tests = $this->getTests();

        // Select DB
        $influxDB = $this->influxClient->selectDB(getenv('INFLUXDB_DB'));

        // Get locations
        $locations = $this->getLocations();

        // Get performance data for each test
        $requests = [];

        foreach ($tests as $testID => $test) {
            $requests[$testID] = new Request('GET', sprintf('Tests/Checks?TestID=%d&Fields=%s', $testID, implode(',', ['status', 'location', 'time', 'performance'])));
        }

        $pool = new Pool($this->client, $requests, [
            'concurrency' => 5,
            'fulfilled' => function (ResponseInterface $response, int $index) use ($influxDB, $tests, $locations) {
                // Get performance data
                $performance = json_decode($response->getBody()->getContents());

                // Get test
                $test = $tests[$index];

                // Data points
                $points = [];

                // Convert performance data to points
                foreach ($performance as $perf) {
                    $location = null;

                    if (array_key_exists($perf->Location, $locations)) {
                        $location = $locations[$perf->Location];
                    }

                    $points[] = new Point(
                        'statuscake_performance',
                        $perf->Performance,
                        [
                            'testID' => $test->TestID,
                            'testType' => $test->TestType,
                            'testName' => $test->WebsiteName
                        ],
                        [
                            'location' => $perf->Location,
                            'country' => $location
                        ],
                        $perf->Time
                    );
                }

                $influxDB->writePoints($points, Database::PRECISION_SECONDS);
            },
            'rejected' => function ($reason, int $index) use ($tests) {
                $this->log->error('Getting performance data for test "{test}" failed: {reason}', [
                    'reason' => $reason,
                    'test' => $tests[$index]->WebsiteName
                ]);
            }
        ]);

        $promise = $pool->promise();
        $promise->wait();

        // Release lock
        $this->release();
    }

    /**
     * Get all StatusCake tests
     * @return \StdObject[]
     */
    private function getTests() : array
    {
        // Check cache
        if ($this->cache->has('tests')) {
            return $this->cache->get('tests');
        }

        /** @var Response $response */
        $response = $this->client->request('GET', 'Tests');

        if ($response->getStatusCode() !== 200) {
            $this->log->critical('Getting test list failed: {code} {reason}', [
                'reason' => $response->getReasonPhrase(),
                'code' => $response->getStatusCode()
            ]);
        }

        // List of tests
        $rawTests = json_decode($response->getBody()->getContents());
        $tests = [];

        foreach ($rawTests as $test) {
            $tests[$test->TestID] = $test;
        }

        // Store in cache
        $this->cache->set('tests', $tests, 3600);

        return $tests;
    }

    /**
     * Get list of beacon locations
     * @return string[]
     */
    private function getLocations() : array
    {
        // Check cache
        if ($this->cache->has('locations')) {
            return $this->cache->get('locations');
        }

        /** @var Response $response */
        $response = $this->client->request('GET', 'Locations/json');

        if ($response->getStatusCode() !== 200) {
            $this->log->critical('Getting locations list failed: {code} {reason}', [
                'reason' => $response->getReasonPhrase(),
                'code' => $response->getStatusCode()
            ]);
        }

        // List of locations
        $rawLocations = json_decode($response->getBody()->getContents());

        $locations = [];

        foreach ($rawLocations as $location) {
            $locations[$location->servercode] = $location->countryiso;
        }

        // Store in cache
        $this->cache->set('locations', $locations, 3600);

        return $locations;
    }
}