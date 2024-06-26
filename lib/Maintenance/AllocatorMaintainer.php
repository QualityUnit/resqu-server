<?php

namespace Resque\Maintenance;

use Resque\Config\GlobalConfig;
use Resque\Key;
use Resque\Log;
use Resque\Pool\AllocatorImage;
use Resque\Pool\BatchAllocatorProcess;
use Resque\Pool\IAllocatorProcess;
use Resque\Pool\JobAllocatorProcess;
use Resque\Process;
use Resque\Resque;
use Resque\SignalHandler;
use Resque\Stats\AllocatorStats;

class AllocatorMaintainer implements IProcessMaintainer {

    const PREFIX_BATCH = 'batch-';
    const PREFIX_JOB = 'job-';

    public function getHumanReadableName() {
        return 'Allocator';
    }

    /**
     * @return AllocatorImage[]
     * @throws \Resque\RedisError
     */
    public function getLocalProcesses() {
        $allocatorIds = Resque::redis()->sMembers(Key::localAllocatorProcesses());
        $images = [];

        foreach ($allocatorIds as $processId) {
            $images[] = AllocatorImage::load($processId);
        }

        return $images;
    }

    /**
     * Cleans up and recovers local processes.
     *
     * @throws \Resque\RedisError
     */
    public function maintain() {
        $jobLimit = GlobalConfig::getInstance()->getAllocatorConfig()->getJobCount();
        $batchLimit = GlobalConfig::getInstance()->getAllocatorConfig()->getBatchCount();

        [$jobAlive, $batchAlive] = $this->cleanupAllocators($jobLimit, $batchLimit);

        for ($i = $jobAlive; $i < $jobLimit; $i++) {
            $this->forkAllocator(self::PREFIX_JOB);
        }

        for ($i = $batchAlive; $i < $batchLimit; $i++) {
            $this->forkAllocator(self::PREFIX_BATCH);
        }

        AllocatorStats::getInstance()->reportStaticQueue(Resque::redis()->lLen(Key::unassigned()));
        AllocatorStats::getInstance()->reportBatchQueue(Resque::redis()->lLen(Key::committedBatchList()));
    }

    public function recover() {
        foreach ($this->getLocalProcesses() as $image) {
            Log::notice('Cleaning up past allocator.', [
                'process_id' => $image->getId()
            ]);
            $image->unregister();
        }
    }

    /**
     * Checks all scheduler processes and keeps at most one alive.
     *
     * @param int $jobLimit
     * @param int $batchLimit
     *
     * @return int[]
     * @throws \Resque\RedisError
     */
    private function cleanupAllocators($jobLimit, $batchLimit) {
        $jobAlive = 0;
        $batchAlive = 0;
        foreach ($this->getLocalProcesses() as $image) {
            // cleanup if dead
            if (!$image->isAlive()) {
                Log::notice('Cleaning up dead allocator.', [
                    'process_id' => $image->getId()
                ]);
                $this->removeAllocatorRecord($image);
                continue;
            }

            $isJob = $this->isJobAllocator($image);
            $isBatch = $this->isBatchAllocator($image);

            // kill and cleanup
            if ($isJob && $jobAlive >= $jobLimit) {
                $this->terminateAllocator($image);
                continue;
            }
            if ($isBatch && $batchAlive >= $batchLimit) {
                $this->terminateAllocator($image);
                continue;
            }

            if ($isJob) {
                $jobAlive++;
            } elseif ($isBatch) {
                $batchAlive++;
            } else {
                $this->terminateAllocator($image);
            }
        }

        return [$jobAlive, $batchAlive];
    }

    /**
     * @param AllocatorImage $image
     *
     * @return IAllocatorProcess|null
     */
    private function createAllocatorProcess(AllocatorImage $image) {
        if ($this->isJobAllocator($image)) {
            return new JobAllocatorProcess($image->getCode());
        }

        if ($this->isBatchAllocator($image)) {
            return new BatchAllocatorProcess($image->getCode());
        }

        Log::error('Invalid allocator type found.', [
            'process_id' => $image->getId()
        ]);

        return null;
    }

    /**
     * @param string $codePrefix
     */
    private function forkAllocator($codePrefix) {
        $pid = Process::fork();
        if ($pid === false) {
            Log::emergency('Unable to fork. Function pcntl_fork is not available.');

            return;
        }

        if ($pid === 0) {
            SignalHandler::instance()->unregisterAll();

            $image = AllocatorImage::create($codePrefix . getmypid());
            Log::info("Creating allocator {$image->getId()}");
            $allocator = $this->createAllocatorProcess($image);
            if ($allocator === null) {
                exit(0);
            }

            try {
                $allocator->register();
                $allocator->work();
                $allocator->unregister();
            } catch (\Throwable $t) {
                Log::error('Allocator process failed.', [
                    'exception' => $t,
                    'process_id' => $image->getId()
                ]);
            }
            exit(0);
        }
    }

    private function isBatchAllocator(AllocatorImage $image) {
        return strpos($image->getCode(), self::PREFIX_BATCH) === 0;
    }

    private function isJobAllocator(AllocatorImage $image) {
        return strpos($image->getCode(), self::PREFIX_JOB) === 0;
    }

    /**
     * @param AllocatorImage $image
     *
     * @throws \Resque\RedisError
     */
    private function removeAllocatorRecord(AllocatorImage $image) {
        $allocatorProcess = $this->createAllocatorProcess($image);

        if ($allocatorProcess !== null) {
            $allocatorProcess->revertBuffer();
        }

        $image->unregister();
    }

    /**
     * @param AllocatorImage $image
     */
    private function terminateAllocator(AllocatorImage $image) {
        Log::notice("Terminating allocator process {$image->getId()}");
        posix_kill($image->getPid(), SIGTERM);
    }
}