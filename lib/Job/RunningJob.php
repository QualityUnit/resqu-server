<?php

namespace Resque\Job;

use Resque\Config\GlobalConfig;
use Resque\Job\Processor\HttpProcessor;
use Resque\Job\Processor\StandardProcessor;
use Resque\Log;
use Resque\Protocol\Job;
use Resque\Resque;
use Resque\Stats\JobStats;
use Resque\Worker\WorkerProcess;

class RunningJob {

    /** @var string */
    private $id;
    /** @var WorkerProcess */
    private $worker;
    /** @var Job */
    private $job;
    /** @var float */
    private $startTime;

    /**
     * @param WorkerProcess $worker
     * @param QueuedJob $queuedJob
     */
    public function __construct(WorkerProcess $worker, QueuedJob $queuedJob) {
        $this->id = $queuedJob->getId();
        $this->worker = $worker;
        $this->job = $queuedJob->getJob();
        $this->startTime = microtime(true);
    }

    public function getProcessorName(): string {
        if (empty($this->getJob()->getIncludePath())) {
            return HttpProcessor::PROCESSOR_NAME;
        }
        return StandardProcessor::PROCESSOR_NAME;
    }

    public function fail(\Throwable $t) {
        $this->reportFail($t);
    }

    /**
     * @return string
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return Job
     */
    public function getJob() {
        return $this->job;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->job->getName();
    }

    /**
     * @return float
     */
    public function getStartTime() {
        return $this->startTime;
    }

    /**
     * @return WorkerProcess
     */
    public function getWorker() {
        return $this->worker;
    }

    /**
     * @throws \Resque\RedisError
     */
    public function reschedule() {
        Resque::enqueue($this->job);
        $this->reportSuccess();
        $this->reportReschedule();
    }

    /**
     * @param $in
     *
     * @throws \Resque\RedisError
     */
    public function rescheduleDelayed($in) {
        Resque::delayedEnqueue($in, $this->job);
        $this->reportSuccess();
        $this->reportReschedule();
    }

    /**
     * @param \Exception $e
     *
     * @throws \Resque\RedisError
     */
    public function retry(\Exception $e) {
        if ($this->job->getFailCount() >= (GlobalConfig::getInstance()->getMaxTaskFails() - 1)) {
            $this->fail($e);

            return;
        }

        $this->job->incFailCount();

        $newJobId = Resque::enqueue($this->job)->getId();
        $this->reportRetry($e, $newJobId);
    }

    /**
     * @param \Exception $e
     *
     * @throws \Resque\RedisError
     */
    public function retryWithBackoff(\Exception $e) {
        if ($this->job->getFailCount() >= (GlobalConfig::getInstance()->getMaxTaskFails() - 1)) {
            $this->fail($e);

            return;
        }

        $this->job->incFailCount();

        $delay = 2 ** $this->job->getFailCount();
        Resque::delayedEnqueue($delay, $this->job);

        Log::notice("Job retry scheduled with backoff delay: {$delay}s", $this->createFailContext($e, $this->getJob()->getUid()));
        JobStats::getInstance()->reportRetry($this);
    }

    public function success() {
        $this->reportSuccess();
    }

    /**
     * @param \Throwable $t
     * @param string $retryText
     *
     * @return mixed[]
     */
    private function createFailContext(\Throwable $t, $retryText = 'no retry') {
        return [
            'start_time' => date('Y-m-d\TH:i:s.uP', (int)$this->startTime),
            'payload' => $this->job->toArray(),
            'exception' => $t,
            'retried_by' => $retryText
        ];
    }

    /**
     * @param \Throwable $t
     */
    private function reportFail(\Throwable $t) {
        Log::error('Job failed.', $this->createFailContext($t));
        JobStats::getInstance()->reportFail($this);
    }

    private function reportReschedule() {
        JobStats::getInstance()->reportReschedule($this);
    }

    /**
     * @param \Exception $e
     * @param string $retryJobId
     */
    private function reportRetry(\Exception $e, $retryJobId) {
        Log::error('Job was retried.', $this->createFailContext($e, $retryJobId));
        JobStats::getInstance()->reportRetry($this);
    }

    private function reportSuccess() {
        $duration = floor((microtime(true) - $this->startTime) * 1000);
        if ($duration < 0) {
            $duration = 0;
        }

        JobStats::getInstance()->reportDuration($this, (int)$duration);
    }
}
