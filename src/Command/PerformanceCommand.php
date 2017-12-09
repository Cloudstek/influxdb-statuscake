<?php declare(strict_types = 1);

namespace Cloudstek\InfluxDB\StatusCake\Command;

use Cloudstek\InfluxDB\StatusCake\Service\StatusCakeInterface;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use InfluxDB\Database;
use InfluxDB\Point;

/**
 * Performance data command
 */
class PerformanceCommand extends Command
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

        // InfluxDB
        $this->influxDatabase = $influxDatabase;
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
        // Don't allow more than one instance
        if (!$this->lock($this->getName())) {
            $this->log->warning('Command "{command}" is already running.', [
                'command' => $this->getName()
            ]);
            return 0;
        }

        // Get list of test IDs
        $tests = $this->statusCake->getTests(900);

        // Get performance data
        $performances = $this->statusCake->getPerformance($tests);

        // Points
        $points = [];

        // Convert performance data to points
        foreach ($performances as $testID => $performance) {
            $test = $tests[$testID];

            foreach ($performance as $time => $details) {
                $points[] = new Point(
                    'statuscake_performance',
                    $details->Performance,
                    [
                        'testID' => $test->TestID,
                        'testType' => $test->TestType,
                        'testName' => $test->WebsiteName,
                        'location' => $details->Location,
                        'country' => $details->Country
                    ],
                    [

                    ],
                    $time
                );
            }
        }

        $this->influxDatabase->writePoints($points, Database::PRECISION_SECONDS);

        // Release lock
        $this->release();
    }
}
