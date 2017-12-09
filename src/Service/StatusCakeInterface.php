<?php declare(strict_types = 1);

namespace Cloudstek\InfluxDB\StatusCake\Service;

interface StatusCakeInterface
{
    public function getTests(?int $ttl = null) : array;
    public function getLocations(?int $ttl = 3600) : array;
    public function getPerformance(array $tests) : array;
}
