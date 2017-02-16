<?php


namespace ResqueSerial\Init;


use Exception;
use Resque;
use ResqueSerial\Key;
use ResqueSerial\QueueLock;
use ResqueSerial\Log;
use ResqueSerial\Serial\QueueImage;
use ResqueSerial\Serial\SerialWorkerImage;
use ResqueSerial\Worker;
use ResqueSerial\WorkerImage;

class Process {

    private $stopping = false;

    private $logger;

    /** @var GlobalConfig */
    private $globalConfig;

    public function __construct() {
        $this->logger = Log::main();
    }

    public function maintain() {
        $this->updateProcLine("maintaining");
        while (true) {
            sleep(5);
            pcntl_signal_dispatch();
            if ($this->stopping) {
                break;
            }
        }
    }

    public function recover() {
        foreach ($this->globalConfig->getQueueList() as $queue) {
            $workerConfig = $this->globalConfig->getWorkerConfig($queue);

            if ($workerConfig == null) {
                Log::main()->error("Invalid worker config for queue $queue");
                continue;
            }

            $blocking = $workerConfig->getBlocking();
            $maxSerialWorkers = $workerConfig->getMaxSerialWorkers();

            $livingWorkerCount = $this->cleanupWorkers($queue);
            $toCreate = $workerConfig->getWorkerCount() - $livingWorkerCount;

            $orphanedSerialWorkers = $this->getOrphanedSerialWorkers($queue);
            $orphanedSerialQueues = $this->getOrphanedSerialQueues($queue);

            for ($i = 0; $i < $toCreate; ++$i) {
                try {
                    $workersToAppend = @$orphanedSerialWorkers[0];
                    $orphanedSerialWorkers = array_slice($orphanedSerialWorkers, 1);

                    $spaceForSerialWorkers = $maxSerialWorkers - count($workersToAppend);
                    $serialQueuesToStart = array_slice($orphanedSerialQueues, 0, $spaceForSerialWorkers);
                    $orphanedSerialQueues = array_slice($orphanedSerialQueues, $spaceForSerialWorkers);

                    $pid = Resque::fork();
                    if ($pid === false) {
                        throw new Exception('Fork returned false.');
                    }

                    if (!$pid) {
                        $worker = new Worker(explode(',', $queue), $this->globalConfig);
                        $worker->setLogger(Log::prefix(getmypid() . "-worker-$queue"));

                        if ($workersToAppend) {
                            foreach ($workersToAppend as $toAppend) {
                                $this->assignSerialWorker($worker, $toAppend);
                            }
                        }

                        foreach ($serialQueuesToStart as $queueToStart) {
                            $this->startSerialQueue($worker, $queueToStart);
                        }

                        $this->logger->notice("Starting worker $worker", array('worker' => $worker));
                        $worker->work(Resque::DEFAULT_INTERVAL, $blocking);
                        exit();
                    }
                } catch (Exception $e) {
                    $this->logger->emergency("Could not fork worker $i for queue $queue", ['exception' => $e]);
                    die();
                }
            }


            // check interruption
            pcntl_signal_dispatch();
            if ($this->stopping) {
                return;
            }
        }
    }

    /**
     * send TERM to all workers and serial workers
     */
    public function shutdown() {
        $this->stopping = true;

        $workers = WorkerImage::all();
        foreach ($workers as $worker) {
            $image = WorkerImage::fromId($worker);
            $this->logger->debug("Killing " . $image->getId() . " " . $image->getPid());
            posix_kill($image->getPid(), SIGTERM);
        }

        $serialWorkers = SerialWorkerImage::all();
        foreach ($serialWorkers as $worker) {
            $image = SerialWorkerImage::fromId($worker);
            $this->logger->debug("Killing " . $image->getId() . " " . $image->getPid());
            posix_kill($image->getPid(), SIGTERM);
        }
    }

    public function start() {
        $this->updateProcLine("starting");
        $this->initialize();
        $this->recover();
    }

    public function updateProcLine($status) {
        $processTitle = "resque-serial-init: $status";
        if(function_exists('cli_set_process_title') && PHP_OS !== 'Darwin') {
            cli_set_process_title($processTitle);
        }
        else if(function_exists('setproctitle')) {
            setproctitle($processTitle);
        }
    }

    /**
     * Assigns serial worker to specified parent worker.
     *
     * @param Worker $parent
     * @param $toAppend
     */
    private function assignSerialWorker(Worker $parent, $toAppend) {
        $image = SerialWorkerImage::fromId($toAppend);

        if (!$image->exists()) {
            return; // ended before we got to it
        }

        $oldParent = WorkerImage::fromId($image->getParent());
        $oldParent->removeSerialWorker($image->getId());

        $newParent = WorkerImage::fromId((string)$parent);
        $newParent->addSerialWorker($image->getId());
        $image->setParent($newParent->getId());
    }

    /**
     * Removes dead workers from queue pool counts the number of living ones.
     *
     * @param string $queue
     *
     * @return int number of living workers on specified queue
     */
    private function cleanupWorkers($queue) {
        $workers = Resque::redis()->smembers(Key::workers());

        $livingWorkers = 0;

        foreach ($workers as $workerId) {
            $image = WorkerImage::fromId($workerId);

            if ($queue != $image->getQueue()) {
                continue; // not this queue
            }
            if (!$image->isLocal()) {
                continue; // not this machine
            }

            if (!$image->isAlive()) {
                // cleanup
                WorkerImage::fromId($workerId)
                        ->removeFromPool()
                        ->clearState()
                        ->clearStarted()
                        ->clearSerialWorkers();
            } else {
                $livingWorkers++;
            }

        }

        return $livingWorkers;
    }

    /**
     * Detects all orphaned serial queues derived from specified queue and returns them.
     * Orphaned serial queue is serial queue without running serial worker.
     *
     * @param string $queue
     *
     * @return string[] list of orphaned serial queue names
     */
    private function getOrphanedSerialQueues($queue) {
        $orphanedQueues = [];
        foreach (QueueImage::all() as $serialQueue) {
            $queueImage = QueueImage::fromName($serialQueue);

            if ($queueImage->getParentQueue() != $queue) {
                continue; // not our queue
            }

            if (QueueLock::exists($serialQueue)) {
                continue; // someone is holding the queue
            }

            $orphanedQueues[] = $serialQueue;
        }

        return $orphanedQueues;
    }

    /**
     * Detects all orphaned serial workers on specified queue and returns them.
     * Orphaned serial worker is running serial worker without running parent worker.
     *
     * @param string $queue
     *
     * @return string[][] map of dead parent worker ID to list of orphaned serial worker IDs
     * that used to have it as a parent
     */
    private function getOrphanedSerialWorkers($queue) {
        $orphanedGroups = [];
        foreach (SerialWorkerImage::all() as $serialWorkerId) {
            $workerImage = SerialWorkerImage::fromId($serialWorkerId);
            $queueImage = QueueImage::fromName($workerImage->getQueue());

            if ($queue != $queueImage->getParentQueue()) {
                continue; // not our queue
            }

            if(!$workerImage->isLocal()) {
                continue; // not our responsibility
            }

            $parent = $workerImage->getParent();

            if ($parent == '') {
                $orphanedGroups['_'][] = $workerImage->getId();
                continue;
            }

            $parentImage = WorkerImage::fromId($parent);

            if (!$parentImage->isAlive()) {
                $orphanedGroups[$parent][] = $workerImage->getId();
            }
        }
        return $orphanedGroups;
    }

    private function initialize() {
        $this->globalConfig = $config = GlobalConfig::instance();
        Resque::setBackend($config->getBackend());

        Log::initFromConfig($config);
        $this->logger = Log::prefix('init-process');

        $this->registerSigHandlers();
    }

    private function registerSigHandlers() {
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);
        pcntl_signal(SIGQUIT, [$this, 'shutdown']);
        $this->logger->debug('Registered signals');
    }

    /**
     * Starts new serial worker on specified serial queue and assigns specified worker as its parent.
     *
     * @param Worker $parent
     * @param string $queueToStart
     */
    private function startSerialQueue(Worker $parent, $queueToStart) {
        // TODO
    }
}