<?php

namespace Resque\Protocol;

class UniqueState {

    /** @var string */
    public $stateName;
    /** @var int */
    public $startTime;

    /**
     * @param string $stateName
     * @param int $startTime
     */
    public function __construct($stateName, $startTime = null) {
        $this->stateName = $stateName;
        $this->startTime = $startTime ?? time();
    }

    public static function fromString(string $stateString) {
        $parts = explode('-', $stateString);

        if (count($parts) < 2) {
            return new self($stateString);
        }

        $time = (int) array_pop($parts);

        return new self(implode('-', $parts), $time);
    }

    public function toString() {
        return "{$this->stateName}-{$this->startTime}";
    }
}