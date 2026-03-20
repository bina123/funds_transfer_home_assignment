<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HealthController — liveness and readiness probe for load balancers and orchestrators.
 *
 * Two levels of health:
 *   GET /health       — liveness: is the process alive?
 *   GET /health/ready — readiness: can the process serve traffic? (checks DB)
 *
 * Liveness is used by Docker/Kubernetes to decide whether to restart the container.
 * Readiness is used to decide whether to route traffic to this instance.
 * Keeping them separate prevents a DB blip from triggering unnecessary pod restarts.
 *
 * Returns 200 when healthy, 503 Service Unavailable when a dependency is down.
 * The response body is machine-readable JSON so monitoring tools can parse it.
 */
#[Route('/health')]
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Liveness probe — just confirms the PHP process is running.
     * Always returns 200 as long as Symfony is handling requests.
     */
    #[Route('', name: 'health_liveness', methods: ['GET'])]
    public function liveness(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    /**
     * Readiness probe — verifies the service can actually handle requests.
     * Checks DB connectivity with a lightweight ping (no table scan).
     * Returns 503 if the DB is unreachable so the load balancer stops routing here.
     */
    #[Route('/ready', name: 'health_readiness', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        try {
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'error';
        }

        $healthy = $db === 'ok';

        return $this->json(
            ['status' => $healthy ? 'ok' : 'degraded', 'db' => $db],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
