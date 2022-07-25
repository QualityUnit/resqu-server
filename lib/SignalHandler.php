<?php


namespace Resque;


class SignalHandler {

    /**
     * @var callable[]
     */
    private array $handlers = [];
    private static self $instance;

    private function __construct() {
    }

    public static function dispatch(): void {
        pcntl_signal_dispatch();
    }

    public static function instance(): self {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getHandler($signal) {
        return $this->handlers[$signal] ?? SIG_DFL;
    }

    public function register($signal, $handler): self {
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signal, $handler);
            $this->handlers[$signal] = $handler;
        }

        return $this;
    }

    public function unregister($signal): self {
        unset($this->handlers[$signal]);
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signal, SIG_DFL);
        }

        return $this;
    }

    public function unregisterAll(): self {
        foreach ($this->handlers as $signal => $handler) {
            $this->unregister($signal);
        }

        return $this;
    }
}