<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * Thrown by RateLimitListener when a client exceeds the allowed request rate.
 * HTTP 429 Too Many Requests — standard fintech API protection.
 */
final class RateLimitExceededException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'Rate limit exceeded. Please try again later.',
            429,
            'rate_limit_exceeded',
        );
    }
}
