<?php

namespace Resque\Protocol;

/**
 * Keep in sync with:
 * https://github.com/QualityUnit/resqu-client/blob/master/lib/Client/Protocol/HttpJobResponse.php
 */
class HttpJobResponse {
    private string $message;
    private ?bool $retry;
    private ?int $rescheduleDelay;

    private function __construct(string $message, ?bool $retry, ?int $rescheduleDelay) {
        $this->message = $message;
        $this->retry = $retry;
        $this->rescheduleDelay = $rescheduleDelay;
    }

    public static function createSuccess(string $message): self {
        return new self($message, null, null);
    }

    public static function createReschedule(string $message, int $rescheduleDelay): self {
        return new self($message, null, $rescheduleDelay);
    }

    public static function createRetry(string $message): self {
        return new self($message, true, null);
    }

    public static function fromArray(array $data): ?self {
        if (!isset($data['message'])) {
            return null;
        }
        return new self($data['message'], $data['retry'] ?? null, $data['rescheduleDelay'] ?? null);
    }

    public function toArray(): array {
        return [
            'message' => $this->message,
            'retry' => $this->retry,
            'rescheduleDelay' => $this->rescheduleDelay
        ];
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function getRescheduleDelay(): ?int {
        return $this->rescheduleDelay;
    }

    public function isReschedule(): bool {
        return $this->rescheduleDelay !== null;
    }

    public function isRetry(): bool {
        return $this->retry === true;
    }
}