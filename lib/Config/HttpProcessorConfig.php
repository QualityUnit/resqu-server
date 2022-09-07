<?php

namespace Resque\Config;

class HttpProcessorConfig {
    public string $jobRunnerEndpointUrl;
    public ?string $jobRunnerHost;
    public int $timeout;
    public int $connectTimeout;
    public int $readTimeout;
    public array $jwtPrivateKeys;
    public string $currentJwtPrivateKey;

    public function __construct(array $data) {
        if (empty($data['job_runner_endpoint_url'])) {
            throw new \InvalidArgumentException('job_runner_endpoint_url not specified.');
        }
        $this->jobRunnerEndpointUrl = $data['job_runner_endpoint_url'];

        if (!empty($data['job_runner_host'])) {
            $this->jobRunnerHost = $data['job_runner_host'];
        }

        if (empty($data['jwt_private_keys'])) {
            throw new \InvalidArgumentException('jwt_private_keys not specified.');
        }
        if (empty($data['jwt_private_key_in_use'])) {
            throw new \InvalidArgumentException('jwt_private_key_in_use not specified.');
        }
        $this->jwtPrivateKeys = $this->loadJwtPrivateKeys($data['jwt_private_keys']);

        $this->currentJwtPrivateKey = $data['jwt_private_key_in_use'];
        if (!isset($this->jwtPrivateKeys[$this->currentJwtPrivateKey])) {
            throw new \InvalidArgumentException("jwt_private_keys array does not include a key specified jwt_private_key_in_use.");
        }

        $this->timeout = $data['timeout'] ?? 0;
        $this->connectTimeout = $data['connect_timeout'] ?? 0;
        $this->readTimeout = $data['read_timeout'] ?? 0;
    }

    private function loadJwtPrivateKeys(array $keys): array {
        $ret = [];
        foreach ($keys as $keyId => $keyFilePath) {
            $keyFilePath = trim($keyFilePath);
            if (empty($keyFilePath)) {
                throw new \InvalidArgumentException("jwt_private_keys[$keyId] is invalid RS256 private key.");
            }
            $ret[$keyId] = $this->loadJwtPrivateKeyFromFile($keyId, $keyFilePath);
        }

        return $ret;
    }

    private function loadJwtPrivateKeyFromFile(string $keyId, string $keyFilePath): string {
        $content = @file_get_contents($keyFilePath);
        if ($content === false) {
            throw new \InvalidArgumentException("jwt_private_keys[$keyId] file failed to be loaded: $keyFilePath");
        }
        return $content;
    }
}