<?php

namespace Resque\Process;

use Resque\Config\GlobalConfig;
use Resque\Log;
use Resque\Process;
use Resque\SignalHandler;
use Resque\StatsD;

abstract class AbstractProcess implements IStandaloneProcess {

    private bool $isShutDown = false;
    private IProcessImage $image;
    private string $title;

    public function __construct(string $title, IProcessImage $image) {
        $this->image = $image;
        $this->title = $title;
    }

    public function getImage(): IProcessImage {
        return $this->image;
    }

    final public function initLogger(): void {
        Log::initialize(GlobalConfig::getInstance()->getLogConfig());
        Log::setPrefix(getmypid() . '-' . $this->title);
    }

    final public function register() {
        Process::setTitlePrefix($this->title);
        Process::setTitle('Initializing');
        $this->initLogger();
        $this->getImage()->register();

        SignalHandler::instance()
            ->unregisterAll()
            ->register(SIGTERM, [$this, 'shutDown'])
            ->register(SIGINT, [$this, 'shutDown'])
            ->register(SIGQUIT, [$this, 'shutDown'])
            ->register(SIGHUP, [$this, 'reloadAll'])
            ->register(SIGUSR1,  function () {
                Log::warning('Received unhandled SIGUSR1.');
            })
            ->register(SIGUSR2, function () {
                Log::warning('Received unhandled SIGUSR2.');
            });

        Log::info('Initialization complete.');
    }

    public function reloadAll(): void {
        Log::notice('Reloading');
        GlobalConfig::reload();
        $this->initLogger();

        StatsD::initialize(GlobalConfig::getInstance()->getStatsConfig());

        Log::notice('Reloaded');
    }

    final public function shutDown(): void {
        $this->isShutDown = true;
        Log::info('Shutting down');
    }

    final public function unregister(): void {
        Process::setTitle('Shutting down');

        $this->getImage()->unregister();

        SignalHandler::instance()->unregisterAll();
        Log::notice('Process unregistered');
    }

    /**
     * The primary loop for a worker.
     * Every $interval (seconds), the scheduled queue will be checked for jobs
     * that should be pushed to Resque\Resque.
     */
    public function work(): void {
        Process::setTitle('Working');
        Log::info('Starting work');

        $this->prepareWork();
        while ($this->canRun()) {

            $this->doWork();

        }
    }

    abstract protected function doWork();

    abstract protected function prepareWork();

    private function canRun(): bool {
        SignalHandler::dispatch();

        return !$this->isShutDown;
    }
}