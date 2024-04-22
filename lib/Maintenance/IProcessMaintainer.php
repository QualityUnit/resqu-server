<?php

namespace Resque\Maintenance;

use Resque\Process\IProcessImage;

interface IProcessMaintainer {

    /**
     * @return IProcessImage[]
     */
    public function getLocalProcesses();

    /**
     * Cleans up and recovers local processes.
     */
    public function maintain();

    /**
     * Recovers after a crash
     */
    public function recover();
}