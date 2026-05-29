<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deliberately "broken on purpose" endpoints used to generate varied observability
 * signals (clean 500s, SQL errors, PHP warnings, high latency, the full 4xx range,
 * mixed log levels). These are not business endpoints — they exist so the later
 * OpenTelemetry / traffic-generation phases have realistic traffic to work against.
 *
 * Mounted under /api/demo, so the `^/api` access_control rule keeps them
 * JWT-authenticated: every request carries an authenticated user + company.
 */
#[Route('/api/demo', name: 'demo_')]
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Baseline healthy endpoint: runs a real (successful) query and returns small JSON.
     */
    #[Route('/ok', name: 'ok', methods: ['GET'])]
    public function ok(): JsonResponse
    {
        $taskCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM task');

        return $this->json([
            'status' => 'ok',
            'tasks' => $taskCount,
        ]);
    }

    /**
     * Throws an uncaught exception → clean 500.
     */
    #[Route('/boom', name: 'boom', methods: ['GET'])]
    public function boom(): never
    {
        throw new \RuntimeException('Deliberate uncaught exception');
    }

    /**
     * Runs invalid SQL → Doctrine DBAL exception bubbles to a 500.
     */
    #[Route('/db-error', name: 'db_error', methods: ['GET'])]
    public function dbError(): never
    {
        $this->connection->executeQuery('SELECT * FROM table_that_does_not_exist');

        throw new \LogicException('Unreachable: the query above must fail');
    }

    /**
     * Provokes a \TypeError → 500.
     */
    #[Route('/type-error', name: 'type_error', methods: ['GET'])]
    public function typeError(): JsonResponse
    {
        // @phpstan-ignore-next-line — intentional type violation
        return $this->json(['length' => $this->expectsString(['not', 'a', 'string'])]);
    }

    private function expectsString(string $value): int
    {
        return \strlen($value);
    }

    /**
     * Emits a PHP warning and a notice, but still returns 200 — the point is the signal.
     */
    #[Route('/warning', name: 'warning', methods: ['GET'])]
    public function warning(): JsonResponse
    {
        @trigger_error('Deliberate user warning', \E_USER_WARNING);

        $data = [];
        // Undefined array key → PHP notice/warning.
        $missing = @$data['missing_key'];

        return $this->json([
            'status' => 'ok',
            'note' => 'a warning and a notice were emitted',
            'missing' => $missing,
        ]);
    }

    /**
     * Deliberately slow: sleeps for a randomized 1–3 s, then returns 200.
     */
    #[Route('/slow', name: 'slow', methods: ['GET'])]
    public function slow(): JsonResponse
    {
        $delayMs = random_int(1000, 3000);
        usleep($delayMs * 1000);

        return $this->json([
            'status' => 'ok',
            'delay_ms' => $delayMs,
        ]);
    }

    /**
     * Returns the requested 4xx client error. Sweeps the whole range from one route
     * template; unknown codes fall back to 422.
     */
    #[Route('/client-error/{code}', name: 'client_error', requirements: ['code' => '\d+'], methods: ['GET'])]
    public function clientError(int $code): never
    {
        throw match ($code) {
            400 => new BadRequestHttpException('Deliberate 400 Bad Request'),
            403 => $this->createAccessDeniedException('Deliberate 403 Forbidden'),
            404 => new NotFoundHttpException('Deliberate 404 Not Found'),
            409 => new ConflictHttpException('Deliberate 409 Conflict'),
            422 => new UnprocessableEntityHttpException('Deliberate 422 Unprocessable Entity'),
            429 => new TooManyRequestsHttpException(message: 'Deliberate 429 Too Many Requests'),
            default => new UnprocessableEntityHttpException(sprintf('Unsupported demo code %d, falling back to 422', $code)),
        };
    }

    /**
     * Emits a log record at the requested level, returns 200. Unknown levels → info.
     */
    #[Route('/log/{level}', name: 'log', methods: ['GET'])]
    public function log(string $level): JsonResponse
    {
        $levels = [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
        ];

        $level = \in_array($level, $levels, true) ? $level : LogLevel::INFO;
        $this->logger->log($level, sprintf('Deliberate demo log at %s level', $level));

        return $this->json([
            'status' => 'ok',
            'logged_level' => $level,
        ]);
    }
}
