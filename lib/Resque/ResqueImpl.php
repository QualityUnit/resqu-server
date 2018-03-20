<?php

namespace Resque;


use Resque\Api\Job;
use Resque\Api\JobDescriptor;
use Resque\Api\ResqueApi;
use Resque\Queue\Queue;
use Resque\Scheduler\PlannedScheduler;
use Resque\Scheduler\SchedulerProcess;

class ResqueImpl implements ResqueApi {

    /** @var Redis Instance of Resque_Redis that talks to redis. */
    private $redis;
    /**
     * @var mixed Host/port combination separated by a colon, or a nested array of server switch
     *         host/port pairs
     */
    private $redisServer;

    /**
     * Internal.
     *
     * @return ResqueImpl
     */
    public static function getInstance() {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return \Resque::getInstance();
    }

    /** @inheritdoc */
    public function enqueue($queue, JobDescriptor $job) {
        return $this->jobEnqueue(Job::fromJobDescriptor($job)->setQueue($queue), true);
    }

    /** @inheritdoc */
    public function enqueueDelayed($delay, $queue, JobDescriptor $job) {
        $this->jobEnqueueDelayed($delay, Job::fromJobDescriptor($job)->setQueue($queue), true);
    }

    public function generateJobId() {
        return md5(uniqid('', true));
    }

    /**
     * @param Job $job
     * @param $checkUnique
     *
     * @return string
     * @throws Api\DeferredException
     * @throws Api\UniqueException
     */
    public function jobEnqueue(Job $job, $checkUnique) {
        return Queue::push($job, $checkUnique)->getId();
    }

    public function jobEnqueueDelayed($delay, Job $job, $checkUnique) {
        SchedulerProcess::schedule(time() + $delay, $job, $checkUnique);
    }

    /** @inheritdoc */
    public function planCreate(\DateTime $startDate, \DateInterval $recurrencePeriod, $queue,
            JobDescriptor $job) {
        if ($recurrencePeriod->invert === 1) {
            throw new Exception('Expected positive recurrence period');
        }

        return PlannedScheduler::insertJob($startDate, $recurrencePeriod, Job::fromJobDescriptor($job)
                ->setQueue($queue));
    }

    /** @inheritdoc */
    public function planRemove($id) {
        return PlannedScheduler::removeJob($id);
    }

    /** @inheritdoc */
    public function redis() {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $this->redis = new Redis($this->redisServer);

        Redis::prefix(\Resque::VERSION_PREFIX);

        return $this->redis;
    }

    public function resetRedis() {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->close();
        } catch (Exception $ignore) {
        }
        $this->redis = null;
    }

    public function setBackend($server, $database = 0) {
        $this->redisServer = $server;
        $this->redis = null;
    }
}