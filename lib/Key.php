<?php


namespace Resque;


use Resque\Config\GlobalConfig;

class Key {

    public static function batchAllocationFailures() {
        return self::of('batch', 'allocation_failures');
    }

    public static function batchPoolBacklogList($poolName, $sourceId) {
        return self::of('pool', $poolName, 'backlog', $sourceId);
    }

    public static function batchPoolQueuesSortedSet($poolName) {
        return self::of('pool', $poolName, 'unit_queues');
    }

    public static function batchPoolSourceNodes($poolName) {
        return self::of('pool', $poolName);
    }

    public static function batchPoolUnitQueueList($poolName, $unitId) {
        return self::of('pool', $poolName, $unitId, 'queues');
    }

    public static function committedBatch($id) {
        return self::of('committed', $id);
    }

    public static function committedBatchList() {
        return self::of('committed');
    }

    /**
     * @param int $at
     *
     * @return string
     */
    public static function delayed($at) {
        return self::of('delayed', $at);
    }

    /**
     * @return string
     */
    public static function delayedQueueSchedule() {
        return 'delayed_queue_schedule';
    }

    /**
     * @param string $allocatorNumber
     *
     * @return string
     */
    public static function localAllocatorBuffer($allocatorNumber) {
        return self::of('allocator', GlobalConfig::getInstance()->getNodeId(), $allocatorNumber);
    }

    /**
     * @return string
     */
    public static function localAllocatorProcesses() {
        return self::of('process', GlobalConfig::getInstance()->getNodeId(), 'allocator');
    }

    /**
     * @param string $poolName
     *
     * @return string
     */
    public static function localPoolProcesses($poolName) {
        return self::of('process', GlobalConfig::getInstance()->getNodeId(), 'pool', $poolName);
    }

    /**
     * @return string
     */
    public static function localSchedulerProcesses() {
        return self::of('process', GlobalConfig::getInstance()->getNodeId(), 'scheduler');
    }

    /**
     * @return string
     */
    public static function nodeIdentifier() {
        return self::of('node_identifier', GlobalConfig::getInstance()->getNodeId());
    }

    /**
     * @param string $id
     *
     * @return string
     */
    public static function plan($id) {
        return self::of('plan', $id);
    }

    public static function planSchedule() {
        return self::of('plan_schedule');
    }

    /**
     * @param int $timestamp
     *
     * @return string
     */
    public static function planTimestamp($timestamp) {
        return self::of('plan_schedule', $timestamp);
    }

    public static function staticPoolQueue($poolName) {
        return self::of('static', 'queue', $poolName);
    }

    public static function unassigned() {
        return self::of('unassigned');
    }

    public static function runLockDeferred($uniqueId) {
        return self::of('unique', $uniqueId, 'deferred');
    }

    public static function runLockState($uniqueId) {
        return self::of('unique', $uniqueId, 'state');
    }

    public static function queueLocks() {
        return self::of('unique', 'queue_locks');
    }

    /**
     * @param string $workerId
     *
     * @return string
     */
    public static function workerBuffer($workerId) {
        return self::of('worker', $workerId);
    }

    /**
     * @param string $workerId
     *
     * @return string
     */
    public static function workerRuntimeInfo($workerId) {
        return self::of('worker', $workerId, 'runtime');
    }

    private static function of(...$parts) {
        return implode(':', $parts);
    }
}