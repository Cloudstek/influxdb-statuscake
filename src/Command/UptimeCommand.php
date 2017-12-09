<?php declare(strict_types = 1);

namespace Cloudstek\InfluxStatusCake\Command;

use Cloudstek\InfluxStatusCake\Service\StatusCakeInterface;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use InfluxDB\Database;
use InfluxDB\Point;

/**
 * Uptime command
 */
class UptimeCommand extends Command
{
    use LockableTrait;

    /**
     * StatusCake
     * @var StatusCakeInterface
     */
    private $statusCake;

    /**
     * InfluxDB
     * @var Database
     */
    private $influxDatabase;

    /**
     * @inheritdoc
     */
    public function __construct(StatusCakeInterface $statusCake, Database $influxDatabase)
    {
        parent::__construct();

        // StatusCake
        $this->statusCake = $statusCake;

        // InfluxDB client
        $this->influxDatabase = $influxDatabase;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('uptime')
            ->setDescription('Store uptime data from StatusCake');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Don't allow more than one instance
        if (!$this->lock($this->getName())) {
            $this->log->warning('Command "{command}" is already running.', [
                'command' => $this->getName()
            ]);
            return 0;
        }

        // Get tests
        $tests = $this->statusCake->getTests(null);

        // Data points
        $points = [];

        foreach ($tests as $test) {
            $points[] = new Point(
                'statuscake_uptime',
                $test->Uptime,
                [
                    'testID' => $test->TestID,
                    'testName' => $test->WebsiteName,
                    'testType' => $test->TestType,
                    'paused' => $test->Paused,
                    'status' => $test->Status
                ]
            );
        }

        $this->influxDatabase->writePoints($points, Database::PRECISION_SECONDS);

        // Release lock
        $this->release();
    }
}