<?php

namespace Resque\Pool;

use Resque;
use Resque\Job\JobParseException;
use Resque\Job\QueuedJob;
use Resque\Key;
use Resque\Log;
use Resque\Process\AbstractProcess;
use Resque\Protocol\QueueLock;
use Resque\Queue\Queue;
use Resque\Stats\AllocatorStats;
use Resque\Stats\JobStats;
use function is_array;

class JobAllocatorProcess extends AbstractProcess implements IAllocatorProcess {

    const BLOCKING_TIMEOUT = 3;

    /** @var Queue */
    private $buffer;
    /** @var Queue */
    private $unassignedQueue;

    /**
     * @param string $code
     */
    public function __construct($code) {
        parent::__construct('job-allocator', AllocatorImage::create($code));

        $this->buffer = new Queue(Key::localAllocatorBuffer($code));
        $this->unassignedQueue = new Queue(Key::unassigned());
    }

    /**
     * main loop
     *
     * @throws Resque\RedisError
     */
    public function doWork() {
        Log::debug('Retrieving job from unassigned jobs');
        $payload = $this->unassignedQueue->popIntoBlocking($this->buffer, self::BLOCKING_TIMEOUT);
        if ($payload === null) {
            $bufferContent = $this->buffer->peek();
            if ($bufferContent === null) {
                Log::debug('No jobs to allocate');
                return;
            }

            $payload = $bufferContent;
            Log::error('Buffer inconsistency detected. Redis is lying again.', [
                'raw_payload' => $payload
            ]);
        }

        $this->processPayload($payload);
    }

    /**
     * @throws Resque\RedisError
     */
    public function revertBuffer() {
        Log::info("Reverting allocator buffer {$this->buffer->getKey()}");
        while (null !== ($payload = $this->buffer->peek())) {
            $queuedJob = $this->queuedJobFromPayload($payload);

            QueueLock::unlockFor($queuedJob);

            $this->buffer->popInto($this->unassignedQueue);
        }
    }

    /**
     * @throws Resque\RedisError
     */
    protected function prepareWork() {
        while (null !== ($payload = $this->buffer->peek())) {
            $this->processPayload($payload);
        }
    }

    private function queuedJobFromPayload($payload) {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            Log::critical('Failed to process unassigned job.', [
                'raw_payload' => $payload
            ]);

            return null;
        }

        try {
            return QueuedJob::fromArray($decoded);
        } catch (JobParseException $e) {
            Log::error('Failed to create job from payload.', [
                'exception' => $e,
                'payload' => $payload
            ]);
        }

        return null;
    }

    /**
     * @param $payload
     *
     * @throws Resque\RedisError if processing failed
     */
    private function processPayload($payload) {
        $queuedJob = $this->queuedJobFromPayload($payload);
        if ($queuedJob === null) {
            $this->buffer->remove($payload);

            return;
        }

        Log::debug("Found job {$queuedJob->getJob()->getName()}");

        if (!QueueLock::lock($queuedJob)) {

            $this->buffer->remove($payload);

            JobStats::getInstance()->reportUniqueDiscarded();

            return;
        }

        $enqueuedPayload = StaticPool::assignJob($queuedJob, $this->buffer);

        AllocatorStats::getInstance()->reportStaticAllocated();

        $this->validatePayload($payload, $enqueuedPayload);
    }

    /**
     * @param string $expected
     * @param string $actual
     */
    private function validatePayload($expected, $actual) {
        if ($expected === $actual) {
            return;
        }

        Log::critical('Enqueued payload does not match processed payload.', [
            'payload' => $expected,
            'actual' => $actual
        ]);
        exit(0);
    }
}