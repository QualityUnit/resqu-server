<?php


namespace Resque\Worker;


use Resque\Job\IJobSource;
use Resque\Job\Processor\HttpProcessor;
use Resque\Job\QueuedJob;
use Resque\Job\RunningJob;
use Resque\Log;
use Resque\Process\AbstractProcess;
use Resque\Protocol\DeferredException;
use Resque\Protocol\DiscardedException;
use Resque\Protocol\Job;
use Resque\Protocol\QueueLock;
use Resque\Protocol\RunningLock;
use Resque\RedisError;
use Resque\Stats\JobStats;

class WorkerProcess extends AbstractProcess {
    private IJobSource $source;
    private HttpProcessor $processor;

    public function __construct(IJobSource $source, WorkerImage $image) {
        parent::__construct("w-{$image->getPoolName()}-{$image->getCode()}", $image);
        $this->source = $source;
        $this->processor = new StandardProcessor();
    }

    public function getImage(): WorkerImage {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return parent::getImage();
    }

    /**
     * @throws RedisError
     */
    protected function doWork(): void {
        $queuedJob = $this->source->bufferNextJob();

        if ($queuedJob === null) {
            return;
        }

        Log::debug("Found job {$queuedJob->getId()}. Processing.");

        if (!$this->acquireLock($queuedJob->getJob())) {
            return;
        }

        $runningJob = $this->startWorkOn($queuedJob);

        try {
            $this->processor->process($runningJob);
            Log::debug("Processing of job {$runningJob->getId()} has finished", [
                'payload' => $runningJob->getJob()->toString()
            ]);
        } catch (\Exception $e) {
            Log::critical('Unexpected error occurred during execution of a job.', [
                'exception' => $e,
                'payload' => $runningJob->getJob()->toArray()
            ]);
        }

        $this->finishWorkOn($queuedJob);
    }

    protected function prepareWork(): void {
        // NOOP
    }

    private function assertJobsEqual(QueuedJob $expected, QueuedJob $actual): void {
        if ($expected->getId() === $actual->getId()) {
            return;
        }

        Log::critical('Dequeued job does not match buffered job.', [
            'payload' => $expected->toString(),
            'actual' => $actual->toString()
        ]);
        exit(0);
    }

    private function finishWorkOn(QueuedJob $queuedJob) {
        $bufferedJob = $this->source->getBuffer()->popJob();
        if ($bufferedJob === null) {
            Log::error('Buffer is empty after processing.', [
                'payload' => $queuedJob->toString()
            ]);
            throw new \RuntimeException('Invalid state.');
        }
        $this->assertJobsEqual($queuedJob, $bufferedJob);

        $this->getImage()->clearRuntimeInfo();
    }

    private function acquireLock(Job $job) {
        $uid = $job->getUid();

        if ($uid === null) {
            return true;
        }

        try {
            RunningLock::lock(
                $uid->getId(),
                $this->source->getBuffer()->getKey(),
                $uid->isDeferrable()
            );

            QueueLock::unlock($uid->getId());

            return true;
        } catch (DeferredException $e) {
            QueueLock::unlock($uid->getId());
            JobStats::getInstance()->reportUniqueDeferred();
        } catch (DiscardedException $e) {
            QueueLock::unlock($uid->getId());
            JobStats::getInstance()->reportUniqueDiscarded();
        }

        return false;
    }

    /**
     * @throws RedisError
     */
    private function startWorkOn(QueuedJob $queuedJob): RunningJob {
        $this->getImage()->setRuntimeInfo(
            microtime(true),
            $queuedJob->getJob()->getName(),
            $queuedJob->getJob()->getUniqueId()
        );
        $runningJob = new RunningJob($this, $queuedJob);
        JobStats::getInstance()->reportJobProcessing($runningJob);

        return $runningJob;
    }
}