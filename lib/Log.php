<?php


namespace Resque;

use Resque\Libs\Monolog\Formatter\LogstashFormatter;
use Resque\Libs\Monolog\Logger;
use Resque\Libs\Monolog\Processor\PsrLogMessageProcessor;
use Resque\Config\LogConfig;
use Resque\Libs\Psr\Log\AbstractLogger;
use Resque\Libs\Psr\Log\LoggerInterface;
use Resque\Log\FileHandler;
use Resque\Log\StreamHandler;

class Log {

    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const DEBUG = 'debug';
    const EMERGENCY = 'emergency';
    const ERROR = 'error';
    const INFO = 'info';
    const LINE_FORMAT = "[%datetime%] %channel%.%level_name%: %message% %context.exception%\n";
    const NOTICE = 'notice';
    const WARNING = 'warning';
    public const CTX_PROCESSOR = 'processor';
    public const CTX_ACCOUNT_ID = 'accountid';

    /** @var Log */
    private static $instance;

    /** @var PrefixLogger */
    private $logger;

    private function __construct() {
        $this->logger = new Logger('default');
    }

    public static function alert($message, array $context = []) {
        self::getInstance()->logger->log(self::ALERT, $message, $context);
    }

    public static function critical($message, array $context = []) {
        self::getInstance()->logger->log(self::CRITICAL, $message, $context);
    }

    public static function debug($message, array $context = []) {
        self::getInstance()->logger->log(self::DEBUG, $message, $context);
    }

    public static function emergency($message, array $context = []) {
        self::getInstance()->logger->log(self::EMERGENCY, $message, $context);
    }

    public static function error($message, array $context = []) {
        self::getInstance()->logger->log(self::ERROR, $message, $context);
    }

    public static function info($message, array $context = []) {
        self::getInstance()->logger->log(self::INFO, $message, $context);
    }

    public static function initialize(LogConfig $config) {
        self::getInstance()->logger = self::createLogger($config);
    }

    public static function initializeConsoleLogger($level = Logger::DEBUG) {
        self::getInstance()->logger = self::createLogger(LogConfig::forPath('php://stdout', $level));
    }

    public static function notice($message, array $context = []) {
        self::getInstance()->logger->log(self::NOTICE, $message, $context);
    }

    public static function setPrefix($prefix) {
        self::getInstance()->logger->setPrefix($prefix);
    }

    public static function warning($message, array $context = []) {
        self::getInstance()->logger->log(self::WARNING, $message, $context);
    }

    private static function createLogger(LogConfig $config) {
        $formatter = new LogstashFormatter(
            $config->getApplicationName(),
            $config->getSystemName(),
            $config->getExtraPrefix(),
            $config->getContextPrefix()
        );

        $handler = new FileHandler($config->getPath(), $config->getLevel());
        $handler->setFormatter($formatter);

        $logger = new Logger('main');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler($handler);

        return new PrefixLogger($logger);
    }

    private static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}

class PrefixLogger extends AbstractLogger {

    /** @var string */
    private $prefix = '';
    /** @var LoggerInterface */
    private $logger;

    /**
     * PrefixLogger constructor.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = []) {
        $context['process'] = $this->prefix;
        $this->logger->log($level, $message, $context);
    }

    public function setPrefix($prefix) {
        $this->prefix = $prefix ?: '';
    }
}
