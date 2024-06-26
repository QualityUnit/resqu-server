<?php

namespace Resque\Maintenance;

use Resque\Key;
use Resque\Log;
use Resque\Process;
use Resque\Process\SchedulerImage;
use Resque\Scheduler\SchedulerProcess;
use Resque\SignalHandler;

class SchedulerMaintainer implements IProcessMaintainer {

    public function getHumanReadableName() {
        return 'Scheduler';
    }

    /**
     * @return SchedulerImage[]
     * @throws \Resque\RedisError
     */
    public function getLocalProcesses() {
        $scheduleIds = \Resque\Resque::redis()->sMembers(Key::localSchedulerProcesses());
        $images = [];

        foreach ($scheduleIds as $processId) {
            $images[] = SchedulerImage::load($processId);
        }

        return $images;
    }

    /**
     * Cleans up and recovers local processes.
     *
     * @return void
     * @throws \Resque\RedisError
     */
    public function maintain() {
        $schedulerIsAlive = $this->cleanupSchedulers();
        if (!$schedulerIsAlive) {
            $this->forkScheduler();
        }
    }

    public function recover() {
        foreach ($this->getLocalProcesses() as $image) {
            Log::notice('Cleaning up past scheduler.', [
                'process_id' => $image->getId()
            ]);
            $image->unregister();
        }
    }

    /**
     * Checks all scheduler processes and keeps at most one alive.
     *
     * @return bool true if at least one scheduler process is alive after cleanup
     * @throws \Resque\RedisError
     */
    private function cleanupSchedulers() {
        $oneAlive = false;
        foreach ($this->getLocalProcesses() as $image) {
            // cleanup if dead
            if (!$image->isAlive()) {
                Log::notice('Cleaning up dead scheduler.', [
                    'process_id' => $image->getId()
                ]);
                $image->unregister();
                continue;
            }
            // kill and cleanup
            if ($oneAlive) {
                Log::notice("Terminating extra scheduler process {$image->getId()}");
                posix_kill($image->getPid(), SIGTERM);
                continue;
            }

            $oneAlive = true;
        }

        return $oneAlive;
    }

    private function forkScheduler() {
        $pid = Process::fork();
        if ($pid === false) {
            Log::emergency('Unable to fork. Function pcntl_fork is not available.');

            return;
        }

        if ($pid === 0) {
            SignalHandler::instance()->unregisterAll();
            Log::info('Creating scheduler ' . getmypid());
            $scheduler = new SchedulerProcess();
            try {
                $scheduler->register();
                $scheduler->work();
                $scheduler->unregister();
            } catch (\Throwable $t) {
                Log::error('Scheduler process failed.', [
                    'exception' => $t
                ]);
            }
            exit(0);
        }
    }
}