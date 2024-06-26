<?php


namespace Resque\Config;


use Resque\Libs\Symfony\Yaml\Exception\ParseException;
use Resque\Libs\Symfony\Yaml\Yaml;
use Resque\Log;
use Resque\Redis;

class GlobalConfig {

    /** @var GlobalConfig */
    private static $instance;

    /** @var LogConfig */
    private $logConfig;
    /** @var StatsConfig */
    private $statsConfig;
    /** @var MappingConfig */
    private $staticPoolMapping;
    /** @var MappingConfig */
    private $batchPoolMapping;
    /** @var BatchPoolConfig */
    private $batchPools;
    /** @var StaticPoolConfig */
    private $staticPools;
    /** @var AllocatorConfig */
    private $allocatorConfig;
    /** @var string */
    private $redisHost = Redis::DEFAULT_HOST;
    /** @var int */
    private $redisPort = Redis::DEFAULT_PORT;
    /** @var string */
    private $taskIncludePath = '/opt';
    /** @var int */
    private $maxTaskFails = 3;
    /** @var string */
    private $nodeId;

    /** @var string */
    private $configPath;
    private ?HttpProcessorConfig $httpProcessor;

    /**
     * @return GlobalConfig
     */
    public static function getInstance() {
        if (!self::$instance) {
            throw new \RuntimeException('No instance of GlobalConfig exist');
        }

        return self::$instance;
    }

    /**
     * @param $path
     *
     * @return GlobalConfig
     */
    public static function initialize($path) {
        self::$instance = new self($path);
        self::reload();

        return self::$instance;
    }

    public static function reload() {
        $self = self::$instance;
        try {
            /** @var mixed[] $data */
            $data = Yaml::parse(file_get_contents($self->configPath));
        } catch (ParseException $e) {
            Log::critical('Failed to load configuration.', [
                'exception' => $e
            ]);
            throw new \RuntimeException('Config file failed to parse.');
        }

        if (empty($data['node_id'])) {
          // get node_id from environment variable
          // this is used in kubernetes deployments where statefulset pod name is used.
          // https://kubernetes.io/docs/concepts/workloads/controllers/statefulset/#pod-name-label
          $nodeId = getenv('RESQU_NODE_ID');
          if (empty($nodeId)) {
            throw new \RuntimeException('Config key node_id or environment variable RESQUE_NODE_ID must be specified.');
          }
          $data['node_id'] = str_replace('-', '_', $nodeId);
        }

        if (!isset($data['node_id']) || !self::isNodeIdValid($data['node_id'])) {
            throw new \RuntimeException('node_id invalid.');
        }

        if (!isset($data['pools'])) {
            throw new \RuntimeException('Pools config section missing.');
        }

        if (!isset($data['mapping'])) {
            throw new \RuntimeException('Mapping config section missing.');
        }

        $redis = $data['redis'];
        if (\is_array($redis)) {
            $self->redisHost = $redis['hostname'];
            $self->redisPort = $redis['port'];
        }
        $taskIncludePath = $data['task_include_path'];
        if ($taskIncludePath) {
            $self->taskIncludePath = $taskIncludePath;
        }

        $httpProcessor = $data['http_processor'] ?? null;
        if (empty($httpProcessor)) {
            throw new \RuntimeException('http_processor config missing.');
        }
        $self->httpProcessor = new HttpProcessorConfig($httpProcessor);

        $failRetries = $data['fail_retries'] ?? -1;
        if ($failRetries >= 0) {
            $self->maxTaskFails = (int)$failRetries;
        }
        $self->nodeId = $data['node_id'];

        $self->logConfig = new LogConfig($data['log']);
        $self->statsConfig = new StatsConfig($data['statsd']);
        $self->staticPoolMapping = new MappingConfig($data['mapping']['static']);
        $self->batchPoolMapping = new MappingConfig($data['mapping']['batch']);
        $self->staticPools = new StaticPoolConfig($data['pools']['static']);
        $self->batchPools = new BatchPoolConfig($data['pools']['batch']);
        $self->allocatorConfig = new AllocatorConfig($data['allocators']);

        $self->validatePoolNames();
    }

    private static function isNodeIdValid($nodeId) {
        return preg_match('/^\w+$/', $nodeId) === 1;
    }

    /**
     * @param string $configPath
     */
    private function __construct($configPath) {
        $this->configPath = $configPath;
    }

    /**
     * @return AllocatorConfig
     */
    public function getAllocatorConfig() {
        return $this->allocatorConfig;
    }

    /**
     * @return string
     */
    public function getBackend() {
        return $this->redisHost . ':' . $this->redisPort;
    }

    /**
     * @return BatchPoolConfig
     */
    public function getBatchPoolConfig() {
        return $this->batchPools;
    }

    /**
     * @return MappingConfig
     */
    public function getBatchPoolMapping() {
        return $this->batchPoolMapping;
    }

    /**
     * @return LogConfig
     */
    public function getLogConfig() {
        return $this->logConfig;
    }

    /**
     * @return int
     */
    public function getMaxTaskFails() {
        return $this->maxTaskFails;
    }

    /**
     * @return string
     */
    public function getNodeId() {
        return $this->nodeId;
    }

    /**
     * @return StaticPoolConfig
     */
    public function getStaticPoolConfig() {
        return $this->staticPools;
    }

    /**
     * @return MappingConfig
     */
    public function getStaticPoolMapping() {
        return $this->staticPoolMapping;
    }

    /**
     * @return StatsConfig
     */
    public function getStatsConfig() {
        return $this->statsConfig;
    }

    /**
     * @return string
     */
    public function getTaskIncludePath() {
        return $this->taskIncludePath;
    }

    private function validatePoolNames() {
        $intersection = array_intersect(
            $this->staticPools->getPoolNames(),
            $this->batchPools->getPoolNames()
        );

        if (!empty($intersection)) {
            throw new ConfigException('All pool names must be unique.');
        }
    }

    public function getHttpProcessor(): ?HttpProcessorConfig {
        return $this->httpProcessor;
    }

}