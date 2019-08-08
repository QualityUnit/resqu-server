<?php

namespace Resque\Protocol;

use Resque\Job\QueuedJob;
use Resque\Key;
use Resque\RedisError;
use Resque\Resque;

class QueueLock {

    /**
     * @param QueuedJob $queuedJob
     *
     * @return bool true if lock was successfully obtained or job is not unique, false otherwise
     * @throws RedisError
     */
    public static function lock(QueuedJob $queuedJob): bool {
        $job = $queuedJob->getJob();
        if (empty($job->getUniqueId())) {
            return true;
        }

        $result = Resque::redis()->hSetNx(Key::queueLocks(), $job->getUniqueId(), self::makeState($queuedJob));

        if ($result === 1) {
            return true;
        }

        if ($result === 0) {
            return false;
        }

        throw new RedisError('Unrecognized answer from redis: "' . var_export($result, true) . '"');
    }

    /**
     * @param string $uniqueId
     *
     * @return bool true if the lock was unlocked, false if there was no lock to unlock
     * @throws RedisError
     */
    public static function unlock($uniqueId): bool {
        if (empty($uniqueId)) {
            return false;
        }

        $result = Resque::redis()->hDel(Key::queueLocks(), $uniqueId);

        if ($result === 1) {
            return true;
        }

        if ($result === 0) {
            return false;
        }

        throw new RedisError('Unrecognized answer from redis: "' . var_export($result, true) . '"');
    }

    /**
     * @param QueuedJob $queuedJob
     */
    public static function unlockFor($queuedJob) {
        if ($queuedJob === null) {
            return;
        }

        $uniqueId = $queuedJob->getJob()->getUniqueId();

        if (empty($uniqueId)) {
            return;
        }

        $result = Resque::redis()->hGet(Key::queueLocks(), $uniqueId);

        if ($result === false) {
            return;
        }

        $lockName = UniqueState::fromString($result)->stateName;

        if ($lockName === $queuedJob->getId()) {
            self::unlock($uniqueId);
        }
    }

    private static function makeState(QueuedJob $queuedJob): string {
        return (new UniqueState($queuedJob->getId()))->toString();
    }
}