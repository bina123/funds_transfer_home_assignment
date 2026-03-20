<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * ApiException — base class for HTTP-mapped exceptions.
 *
 * Lives in Shared/ (not Domain/) because HTTP status codes are a
 * transport concern, not a business concern. The ExceptionListener
 * catches these and converts them to JSON responses.
 *
 * Domain exceptions (InsufficientFundsException, CurrencyMismatchException)
 * do NOT extend this — they are pure business failures with no HTTP knowledge.
 */
abstract class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
