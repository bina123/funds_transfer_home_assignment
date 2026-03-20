<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Shared\Exception\RateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * RateLimitListener — enforces per-IP rate limits on the transfer endpoint.
 *
 * Returns a 429 response directly (instead of throwing an exception)
 * so we can attach the Retry-After header — required by RFC 6585.
 * The header tells the client exactly when it may retry, preventing
 * aggressive retry storms that would worsen the overload situation.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
final class RateLimitListener
{
    public function __construct(
        private readonly RateLimiterFactory $transferApiLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->getPathInfo() !== '/api/v1/transfers' || $request->getMethod() !== 'POST') {
            return;
        }

        $limiter = $this->transferApiLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

            $response = new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'message' => 'Rate limit exceeded. Please try again later.',
            ], 429);

            // RFC 6585 § 4 — Retry-After tells the client when it may retry.
            // Without this header, clients either retry immediately (worsening load)
            // or implement their own arbitrary backoff.
            $response->headers->set('Retry-After', (string) max($retryAfter, 1));

            $event->setResponse($response);
        }
    }
}
