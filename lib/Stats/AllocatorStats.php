<?php

namespace Resque\Stats;

use Resque\SingletonTrait;

class AllocatorStats {

    use SingletonTrait;

    /**
     * Reports the number of allocated batches
     */
    public function reportBatchAllocated() {
        Stats::global()->increment('alloc.batch.allocated');
    }

    /**
     * Reports the number of batches waiting to be allocated
     *
     * @param int $length Number of batches waiting to be allocated
     */
    public function reportBatchQueue(int $length) {
        Stats::global()->gauge('alloc.batch.queue', $length);
    }

    /**
     * Reports the number of allocated jobs
     */
    public function reportStaticAllocated() {
        Stats::global()->increment('alloc.static.allocated');
    }

    /**
     * Reports the number of jobs waiting to be allocated
     *
     * @param int $length Number of jobs waiting to be allocated
     */
    public function reportStaticQueue(int $length) {
        Stats::global()->gauge('alloc.static.queue', $length);
    }
}