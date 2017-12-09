<?php declare(strict_types = 1);

namespace Cloudstek\InfluxDB\StatusCake\Service;

use GuzzleHttp\Pool;
use GuzzleHttp\ClientInterface;

use GuzzleHttp\Psr7\Request;

use Symfony\Component\Cache\Simple\FilesystemCache;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class StatusCake implements StatusCakeInterface
{
    /**
     * Cache
     * @var CacheInterface
     */
    private $cache;

    /**
     * HTTP Client
     * @var ClientInterface
     */
    private $client;

    /**
     * Logger
     * @var LoggerInterface|null
     */
    private $log;

    /**
     * StatusCake
     * @param ClientInterface $httpClient HTTP Client
     * @param LoggerInterface|null $log Logger interface
     * @param CacheInterface|null $cache Cache interface
     */
    public function __construct(
        ClientInterface $httpClient,
        ?LoggerInterface $log = null,
        ?CacheInterface $cache = null
    ) {
        // HTTP client
        $this->client = $httpClient;

        // Initialize cache
        $this->cache = ($cache ?? new FilesystemCache());

        // Logger
        $this->log = $log;
    }

    /**
     * Get list of all tests
     * @param int|null $ttl Cache TTL (null disables cache)
     * @return array
     */
    public function getTests(?int $ttl = null) : array
    {
        // Check cache
        if ($ttl !== null && $this->cache->has('tests')) {
            $tests = $this->cache->get('tests');

            if ($tests !== null) {
                return $tests;
            }
        }

        /** @var ResponseInterface $response */
        $response = $this->client->request('GET', 'Tests');

        if ($response->getStatusCode() !== 200) {
            $this->log->critical('Getting test list failed: {code} {reason}', [
                'reason' => $response->getReasonPhrase(),
                'code' => $response->getStatusCode()
            ]);

            return [];
        }

        // List of tests
        $rawTests = json_decode($response->getBody()->getContents());
        $tests = [];

        foreach ($rawTests as $test) {
            $tests[$test->TestID] = $test;
        }

        // Store in cache
        if ($ttl !== null) {
            $this->cache->set('tests', $tests, $ttl);
        }

        return $tests;
    }

    /**
     * Get probe locations
     * @param int|null $ttl Cache TTL (null disables cache)
     * @return array
     */
    public function getLocations(?int $ttl = null) : array
    {
        // Check cache
        if ($ttl !== null && $this->cache->has('locations')) {
            $locations = $this->cache->get('locations');

            if ($locations !== null) {
                return $locations;
            }
        }

        /** @var Response $response */
        $response = $this->client->request('GET', 'Locations/json');

        if ($response->getStatusCode() !== 200) {
            $this->log->critical('Getting locations list failed: {code} {reason}', [
                'reason' => $response->getReasonPhrase(),
                'code' => $response->getStatusCode()
            ]);

            return [];
        }

        // List of locations
        $rawLocations = json_decode($response->getBody()->getContents());
        $locations = [];

        foreach ($rawLocations as $location) {
            $locations[$location->servercode] = $location->countryiso;
        }

        // Store in cache
        if ($ttl !== null) {
            $this->cache->set('locations', $locations, $ttl);
        }

        return $locations;
    }

    /**
     * Get performance data for tests
     * @param object[] $tests Array of tests
     * @return array
     */
    public function getPerformance(array $tests) : array
    {
        // Get locations
        $locations = $this->getLocations(3600);

        // Responses
        $responses = [];

        // Get performance data for each test
        $requests = [];

        foreach ($tests as $testID => $test) {
            $requests[$testID] = new Request('GET', sprintf(
                'Tests/Checks?TestID=%d&Fields=%s',
                $testID,
                implode(',', ['status', 'location', 'time', 'performance'])
            ));
        }

        $pool = new Pool($this->client, $requests, [
            'concurrency' => 5,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$responses) : void {
                $responses[$index] = $response;
            },
            'rejected' => function ($reason, int $index) use ($tests) : void {
                $this->log->error('Getting performance data for test "{test}" failed: {reason}', [
                    'reason' => $reason,
                    'test' => $tests[$index]->WebsiteName
                ]);
            }
        ]);

        $pool->promise()->wait();

        // Data points
        $performances = [];

        foreach ($responses as $testID => $response) {
            // Get performance data
            $performances[$testID] = json_decode($response->getBody()->getContents());

            // Add country code of probe location
            foreach ($performances[$testID] as &$performance) {
                $performance->Country = null;

                if (array_key_exists($performance->Location, $locations)) {
                    $performance->Country = $locations[$performance->Location];
                }
            }
        }

        return $performances;
    }
}
