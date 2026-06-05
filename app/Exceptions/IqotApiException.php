<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Ошибка взаимодействия с IQOT API. Несёт httpStatus, error.code, X-Request-Id
 * и error.details[]. Порт из LazyLift.
 */
class IqotApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestIdHeader = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429 || $this->errorCode === 'rate_limit_exceeded';
    }
}
