<?php


namespace Resque\Queue;


use Resque;
use Resque\Job\IJobSource;
use Resque\Job\Job;
use Resque\Job\QueuedJob;
use Resque\Job\SerialJobLink;
use Resque\Key;
use Resque\ResqueImpl;
use Resque\Stats;

class SerialQueue implements IJobSource {

    /** @var string */
    private $name;
    /** @var Stats */
    private $stats;

    public function __construct($name, Stats $stats) {
        $this->name = $name;
        $this->stats = $stats;
    }

    /**
     * @param Job $job \
     *
     * @return QueuedJob
     */
    public static function push(Job $job) {
        $mainQueue = $job->getQueue();
        $serialQueueImage = SerialQueueImage::create($mainQueue, $job->getSerialId());

        // push serial job to proper serial sub-queue
        $subQueue = $serialQueueImage->generateSubqueueName($job->getSecondarySerialId());
        $serialJob = Job::fromArray($job->toArray())->setQueue($subQueue);
        $queuedJob = new QueuedJob($serialJob, ResqueImpl::getInstance()->generateJobId());
        Resque::redis()->rpush(Key::serialQueue($subQueue), json_encode($queuedJob->toArray()));

        // push serial queue link to main queue if it's not already being worked on
        $serialQueue = $serialQueueImage->getQueueName();
        if (!$serialQueueImage->lockExists() && !SerialJobLink::exists($serialQueue)) {
            SerialJobLink::register($serialQueue);
            $serialLink = SerialJobLink::create($serialQueue);
            $queuedLink = new QueuedJob($serialLink, $queuedJob->getId());
            Resque::redis()->rpush(Key::queue($mainQueue), json_encode($queuedLink->toArray()));
        }

        return $queuedJob;
    }

    /**
     * @return Stats
     */
    function getStats() {
        return $this->stats;
    }

    /**
     * @inheritdoc
     */
    function popBlocking($timeout) {
        $payload = Resque::redis()->blpop(Key::serialQueue($this->name), $timeout);
        if (!is_array($payload) || !isset($payload[1])) {
            return null;
        }

        $data = json_decode($payload[1], true);
        if (!is_array($data)) {
            return null;
        }

        $queuedJob = QueuedJob::fromArray($data);
        $this->writeStats($queuedJob);

        return $queuedJob;
    }

    /**
     * @inheritdoc
     */
    function popNonBlocking() {
        $data = json_decode(Resque::redis()->lpop(Key::serialQueue($this->name)), true);
        if (!is_array($data)) {
            return null;
        }

        $queuedJob = QueuedJob::fromArray($data);
        $this->writeStats($queuedJob);

        return $queuedJob;
    }

    /**
     * @inheritdoc
     */
    public function toString() {
        return $this->name;
    }

    private function writeStats(QueuedJob $queuedJob) {
        $timeQueued = floor((microtime(true) - $queuedJob->getQueuedTime()) * 1000);
        $this->stats->incQueueTime($timeQueued);
        $this->stats->incDequeued();
    }
}