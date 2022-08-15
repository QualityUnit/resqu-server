<?php

namespace Resque\Job\Processor;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\RequestOptions;
use Resque\Protocol\HttpJobResponse;
use Resque\Config\HttpProcessorConfig;
use Resque\Job\JobParseException;
use Resque\Job\RunningJob;
use Resque\Log;
use Resque\Protocol\Job;
use Resque\Protocol\RunningLock;
use Resque\Protocol\UniqueLockMissingException;
use Resque\RedisError;
use Resque\Resque;

class HttpProcessor implements IProcessor {
    public const PROCESSOR_NAME = 'Http';
    
    private HttpProcessorConfig $config;

    public function __construct(HttpProcessorConfig $config) {
        $this->config = $config;
    }

    public function process(RunningJob $runningJob): void {
        try {
            $this->runJob($runningJob);
        } catch (\Throwable $t) {
            try {
                $runningJob->fail($t);
                RunningLock::clearLock($runningJob->getJob()->getUniqueId());
            } catch (\Throwable $r) {
                Log::critical('Failed to properly handle job failure.', [
                    Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                    'exception' => $r,
                    'payload' => $runningJob->getJob()->toArray()
                ]);
            }
        }
    }

    private function runJob(RunningJob $runningJob): void {
        try {
            $job = $runningJob->getJob();

            $client = new Client();

            $url = str_replace('{sourceId}', $job->getSourceId(), $this->config->jobRunnerEndpointUrl);
            $body = $job->toArray();

            Log::debug('HTTP job request.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'jobId' => $job->getUniqueId(),
                'request' => [
                    'url' => $url,
                    'body' => $body
                ]
            ]);

            $headers = [
                'Authorization' => "Bearer {$this->createBearerToken($job)}"
            ];

            if (!empty($this->config->jobRunnerHost)) {
                $headers['Host'] = str_replace('{sourceId}', $job->getSourceId(), $this->config->jobRunnerHost);
            }

            $response = $client->request('POST', $url, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::JSON => $body,
                RequestOptions::CONNECT_TIMEOUT => $this->config->connectTimeout,
                RequestOptions::TIMEOUT => $this->config->timeout,
                RequestOptions::READ_TIMEOUT => $this->config->readTimeout
            ]);

            $responseBody = $response->getBody()->getContents();

            Log::debug('HTTP job success response.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'jobId' => $job->getUniqueId(),
                'response' => [
                    'body' => $responseBody
                ]
            ]);

            $protocolResponse = $this->createProtocolResponse($responseBody);
            if ($protocolResponse === null) {
                Log::warning('Invalid HTTP job runner response, but marking job as successful anyway.', [
                    Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                    'response' => [
                        'body' => $responseBody
                    ]
                ]);
                $this->reportSuccess($runningJob);
            } else if ($protocolResponse->isRetry()) {
                $this->retryJob($runningJob, $protocolResponse->getMessage());
            } else if ($protocolResponse->isReschedule()) {
                Log::info('Rescheduling job.', [
                    Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                    'jobId' => $job->getUniqueId(),
                    'jobName' => $runningJob->getName(),
                    'rescheduleDelay' => $protocolResponse->getRescheduleDelay()
                ]);
                $this->rescheduleJob($runningJob, $protocolResponse->getRescheduleDelay());
            } else {
                $this->reportSuccess($runningJob);
            }
        } catch (BadResponseException $e) {
            Log::error('HTTP job runner error response.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'jobId' => $job->getUniqueId(),
                'jobName' => $job->getName(),
                'response' => [
                    'statusCode' => $e->getResponse()->getStatusCode(),
                    'body' => $e->getResponse()->getBody()->getContents()
                ]
            ]);

            RunningLock::clearLock($runningJob->getJob()->getUniqueId());
            $runningJob->fail($e);
        }
    }

    private function createBearerToken(Job $job): string {
        $iat = time();
        return JWT::encode([
            'iss' => 'resque-server.la',
            'aud' => $job->getSourceId() . '.app-q.la',
            'exp' => $iat + 60,
            'iat' => $iat
        ], $this->config->jwtPrivateKeys[$this->config->currentJwtPrivateKey], 'RS256', $this->config->currentJwtPrivateKey);
    }

    private function createProtocolResponse(string $responseBody): ?HttpJobResponse {
        $jsonBody = json_decode($responseBody, true);
        if ($jsonBody === null) {
            return null;
        }
        return HttpJobResponse::fromArray($jsonBody);
    }

    /**
     * @throws RedisError
     */
    private function enqueueDeferred(Job $deferredJob): void {
        Log::info("Enqueuing deferred job", [
            Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
            'jobId' => $deferredJob->getUniqueId(),
            'jobName' => $deferredJob->getName()
        ]);

        $delay = $deferredJob->getUid()->getDeferralDelay();
        if ($delay > 0) {
            Resque::delayedEnqueue($delay, $deferredJob);
        } else {
            Resque::enqueue($deferredJob);
        }
    }

    /**
     * @throws JobParseException
     */
    private function unlockJob(Job $job): ?Job {
        if ($job->getUniqueId() === null) {
            return null;
        }

        try {
            $payload = RunningLock::unlock($job->getUniqueId());
        } catch (UniqueLockMissingException $e) {
            Log::error('No unique key found on unique job finalization.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'payload' => $job->toArray()
            ]);
            return null;
        }

        if ($payload === false) {
            return null;
        }

        $deferred = json_decode($payload, true);
        if (!\is_array($deferred)) {
            Log::error('Unexpected deferred payload.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'raw_payload' => $payload
            ]);

            return null;
        }

        return Job::fromArray($deferred);
    }

    private function retryJob(RunningJob $runningJob, string $message): void {
        RunningLock::clearLock($runningJob->getJob()->getUniqueId());
        $runningJob->retry(new \Exception($message));
    }

    private function reportSuccess(RunningJob $runningJob): void {
        try {
            $runningJob->success();
        } catch (\Exception $e) {
            Log::error('Failed to report success of a job.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'exception' => $e,
                'payload' => $runningJob->getJob()->toArray()
            ]);
        }

        try {
            $deferredJob = $this->unlockJob($runningJob->getJob());
            if ($deferredJob !== null) {
                $this->enqueueDeferred($deferredJob);
            }
        } catch (JobParseException $e) {
            Log::error('Failed to enqueue deferred job.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'exception' => $e,
                'payload' => $e->getPayload()
            ]);
        }
    }

    /**
     * @throws RedisError
     */
    private function rescheduleJob(RunningJob $runningJob, int $delay): void {
        try {
            RunningLock::clearLock($runningJob->getJob()->getUniqueId());
            if ($delay > 0) {
                $runningJob->rescheduleDelayed($delay);
            } else {
                $runningJob->reschedule();
            }
        } catch (\Exception $e) {
            Log::critical('Failed to reschedule a job.', [
                Log::CTX_PROCESSOR => self::PROCESSOR_NAME,
                'exception' => $e,
                'payload' => $runningJob->getJob()->toArray()
            ]);
            RunningLock::clearLock($runningJob->getJob()->getUniqueId());
        }
    }
}