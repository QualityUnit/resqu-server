<?php

namespace Resque\Job\Processor;

use Resque\Config\GlobalConfig;
use Resque\Job\FailException;
use Resque\Job\JobParseException;
use Resque\Job\RunningJob;
use Resque\Log;
use Resque\Process;
use Resque\Process\SignalTracker;
use Resque\Protocol\Exceptions;
use Resque\Protocol\Job;
use Resque\Protocol\RunningLock;
use Resque\Protocol\UniqueLockMissingException;
use Resque\RedisError;
use Resque\Resque;

class StandardProcessor implements IProcessor {
    public const PROCESSOR_NAME = 'Standard';

    const CHILD_SIGNAL_TIMEOUT = 5;
    const SIGNAL_SUCCESS = SIGUSR2;

    private SignalTracker $successTracker;

    public function __construct() {
        $this->successTracker = new SignalTracker(self::SIGNAL_SUCCESS);
    }

    /**
     * @throws RedisError
     */
    public function process(RunningJob $runningJob) {
        $this->successTracker->register();

        $pid = Process::fork();
        if ($pid === 0) {
            // CHILD PROCESS START
            try {
                $workerPid = (int)$runningJob->getWorker()->getImage()->getPid();
                Log::setPrefix("$workerPid-std-proc-" . posix_getpid());
                Process::setTitlePrefix("$workerPid-std-proc");
                Process::setTitle("Processing job {$runningJob->getName()}");
                $this->handleChild($runningJob);
                Process::signal(self::SIGNAL_SUCCESS, $workerPid);
            } catch (\Throwable $t) {
                try {
                    $runningJob->fail($t);
                    RunningLock::clearLock($runningJob->getJob()->getUniqueId());
                    Process::signal(self::SIGNAL_SUCCESS, $workerPid ?? 0);
                } catch (\Throwable $r) {
                    Log::critical('Failed to properly handle job failure.', [
                        Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                        Log::CTX_ACCOUNT_ID => $runningJob->getJob()->getSourceId(),
                        'exception' => $r,
                        'payload' => $runningJob->getJob()->toArray()
                    ]);
                }
            }
            exit(0);
            // CHILD PROCESS END
        } else {
            try {
                $exitCode = $this->waitForChildProcess($pid);
                if ($exitCode !== 0) {
                    Log::warning("Job process signalled success, but exited with code $exitCode.", [
                        Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                        Log::CTX_ACCOUNT_ID => $runningJob->getJob()->getSourceId(),
                        'payload' => $runningJob->getJob()->toArray()
                    ]);
                }
            } catch (\Exception $e) {
                $runningJob->fail(new FailException("Job execution failed: {$e->getMessage()}"));
                RunningLock::clearLock($runningJob->getJob()->getUniqueId());
            } finally {
                $this->successTracker->unregister();
            }
        }
    }

    /**
     * @throws FailException
     */
    private function createTask(RunningJob $runningJob) {
        $job = $runningJob->getJob();
        try {
            $this->setupEnvironment($job);
            $this->includePath($job);

            if (!class_exists($job->getClass())) {
                throw new TaskCreationException("Job class {$job->getClass()} does not exist.");
            }

            if (!method_exists($job->getClass(), 'perform')) {
                throw new TaskCreationException("Job class {$job->getClass()} does not contain a perform method.");
            }

            $className = $job->getClass();
            $task = new $className;
            $task->args = $job->getArgs();

            return $task;
        } catch (\Throwable $e) {
            $message = 'Failed to create a task instance';
            Log::error("$message from payload.", [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $job->getSourceId(),
                'exception' => $e,
                'payload' => $job->toArray()
            ]);
            throw new FailException($message, 0, $e);
        }
    }

    /**
     * @param Job $deferredJob
     *
     * @throws RedisError
     */
    private function enqueueDeferred(Job $deferredJob) {
        Log::debug("Enqueuing deferred job {$deferredJob->getName()}", [
            Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
            Log::CTX_ACCOUNT_ID => $deferredJob->getSourceId(),
            'payload' => $deferredJob->toArray()
        ]);

        $delay = $deferredJob->getUid()->getDeferralDelay();
        if ($delay > 0) {
            Resque::delayedEnqueue($delay, $deferredJob);
        } else {
            Resque::enqueue($deferredJob);
        }
    }

    /**
     * @param Job $job
     *
     * @return null|Job
     * @throws JobParseException
     * @throws RedisError
     */
    private function unlockJob(Job $job) {
        if ($job->getUniqueId() === null) {
            return null;
        }

        try {
            $payload = RunningLock::unlock($job->getUniqueId());
        } catch (UniqueLockMissingException $e) {
            Log::error('No unique key found on unique job finalization.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $job->getSourceId(),
                'payload' => $job->toArray()
            ]);
            return null;
        }

        if ($payload === false) {
            return null;
        }

        $deferred = json_decode($payload, true);
        if (!\is_array($deferred)) {
            Log::error('Unexpected deferred payload.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $job->getSourceId(),
                'raw_payload' => $payload
            ]);

            return null;
        }

        return Job::fromArray($deferred);
    }

    /**
     * @throws RedisError
     */
    private function handleChild(RunningJob $runningJob): void {
        $job = $runningJob->getJob();
        try {
            Log::debug("Creating task {$job->getClass()}", [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $job->getSourceId(),
            ]);
            $task = $this->createTask($runningJob);
            Log::debug("Performing task {$job->getClass()}", [
                Log::CTX_ACCOUNT_ID => $job->getSourceId(),
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
            ]);

            $task->perform();
        } catch (\Exception $e) {
            $this->handleException($runningJob, $e);

            return;
        }

        $this->reportSuccess($runningJob);

        try {
            $deferredJob = $this->unlockJob($job);
            if ($deferredJob !== null) {
                $this->enqueueDeferred($deferredJob);
            }
        } catch (JobParseException $e) {
            Log::error('Failed to enqueue deferred job.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $job->getSourceId(),
                'exception' => $e,
                'payload' => $e->getPayload()
            ]);
        }
    }

    /**
     * @throws RedisError
     */
    private function handleException(RunningJob $runningJob, \Exception $e): void {
        if (\get_class($e) === \RuntimeException::class) {
            switch ($e->getCode()) {
                case Exceptions::CODE_RETRY:
                    RunningLock::clearLock($runningJob->getJob()->getUniqueId());
                    $runningJob->retry($e);

                    return;
                case Exceptions::CODE_RESCHEDULE:
                    $delay = json_decode($e->getMessage(), true)['delay'] ?? 0;
                    Log::debug("Rescheduling task {$runningJob->getName()} in {$delay}s", [
                        Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                        Log::CTX_ACCOUNT_ID => $runningJob->getJob()->getSourceId(),
                    ]);
                    $this->rescheduleJob($runningJob, $delay);

                    return;
                default: // fall through
            }
        }

        RunningLock::clearLock($runningJob->getJob()->getUniqueId());
        $runningJob->fail($e);
    }

    private function includePath(Job $job): void {
        $jobPath = ltrim(trim($job->getIncludePath()), DIRECTORY_SEPARATOR);
        if (!$jobPath) {
            return;
        }

        $includePath = GlobalConfig::getInstance()->getTaskIncludePath();
        $includePath = str_replace('{sourceId}', $job->getSourceId(), $includePath);
        $includePath = rtrim($includePath, DIRECTORY_SEPARATOR);
        if (is_link($includePath)) {
            $includePath = readlink($includePath);
        }

        require_once $includePath . DIRECTORY_SEPARATOR . $jobPath;
    }

    private function reportSuccess(RunningJob $runningJob): void {
        try {
            $runningJob->success();
        } catch (\Exception $e) {
            Log::error('Failed to report success of a job.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $runningJob->getJob()->getSourceId(),
                'exception' => $e,
                'payload' => $runningJob->getJob()->toArray()
            ]);
        }
    }

    /**
     * @throws RedisError
     */
    private function rescheduleJob(RunningJob $runningJob, int $delay): void {
        try {
            RunningLock::clearLock($runningJob->getJob()->getUniqueId());
            if ($delay > 0) {
                $runningJob->rescheduleDelayed($delay);
            } else {
                $runningJob->reschedule();
            }
        } catch (\Exception $e) {
            Log::critical('Failed to reschedule a job.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                Log::CTX_ACCOUNT_ID => $runningJob->getJob()->getSourceId(),
                'exception' => $e,
                'payload' => $runningJob->getJob()->toArray()
            ]);
            RunningLock::clearLock($runningJob->getJob()->getUniqueId());
        }
    }

    private function setupEnvironment(Job $job): void {
        $env = $job->getEnvironment();
        if (\is_array($env)) {
            foreach ($env as $key => $value) {
                $_SERVER[$key] = $value;
            }
        }
    }

    /**
     * @param $pid
     *
     * @return int
     * @throws \Exception
     */
    private function waitForChildProcess($pid): int {
        $status = "Forked $pid at " . date('Y-m-d H:i:s');
        Process::setTitle($status);
        Log::info($status);

        if (!$this->successTracker->receivedFrom($pid)) {
            while (!$this->successTracker->waitFor($pid, self::CHILD_SIGNAL_TIMEOUT)) {
                // NOOP
            }
        }
        $this->successTracker->unregister();

        return Process::waitForPid($pid);
    }
}