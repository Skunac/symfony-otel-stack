<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Documents the plain-controller demo endpoints (App\Controller\DemoController) in the
 * API Platform OpenAPI / Swagger UI. Plain Symfony controllers are invisible to API
 * Platform, which only knows about #[ApiResource] operations, so we decorate the OpenAPI
 * factory and inject the paths by hand. Controllers stay untouched.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class DemoOpenApiFactory implements OpenApiFactoryInterface
{
    private const TAG = 'Demo';

    public function __construct(
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/demo/ok', $this->get(
            'demoOk',
            'Healthy baseline endpoint',
            'Runs a real (successful) query and returns small JSON.',
            [200 => 'OK'],
        ));

        $paths->addPath('/api/demo/boom', $this->get(
            'demoBoom',
            'Throws an uncaught exception',
            'Throws a \RuntimeException — produces a clean 500.',
            [500 => 'Internal Server Error'],
        ));

        $paths->addPath('/api/demo/db-error', $this->get(
            'demoDbError',
            'Triggers a SQL error',
            'Runs invalid SQL; the Doctrine DBAL exception bubbles to a 500.',
            [500 => 'Internal Server Error'],
        ));

        $paths->addPath('/api/demo/type-error', $this->get(
            'demoTypeError',
            'Triggers a PHP TypeError',
            'Calls a typed helper with the wrong type — produces a 500.',
            [500 => 'Internal Server Error'],
        ));

        $paths->addPath('/api/demo/warning', $this->get(
            'demoWarning',
            'Emits a PHP warning and notice',
            'Triggers an E_USER_WARNING and an undefined-array-key notice, but still returns 200.',
            [200 => 'OK'],
        ));

        $paths->addPath('/api/demo/slow', $this->get(
            'demoSlow',
            'Deliberately slow',
            'Sleeps for a randomized 1–3 s, then returns 200.',
            [200 => 'OK'],
        ));

        $paths->addPath('/api/demo/client-error/{code}', $this->get(
            'demoClientError',
            'Returns the requested 4xx',
            'Maps {code} to the matching client error (400, 403, 404, 409, 422, 429); unknown codes fall back to 422.',
            [400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests'],
            [new Parameter('code', 'path', 'HTTP 4xx status code to return', true, schema: ['type' => 'integer', 'example' => 422])],
        ));

        $paths->addPath('/api/demo/log/{level}', $this->get(
            'demoLog',
            'Emits a log record at the given level',
            'Logs a record at {level} (debug, info, notice, warning, error, critical); unknown levels fall back to info.',
            [200 => 'OK'],
            [new Parameter('level', 'path', 'PSR-3 log level', true, schema: ['type' => 'string', 'enum' => ['debug', 'info', 'notice', 'warning', 'error', 'critical'], 'example' => 'error'])],
        ));

        return $openApi;
    }

    /**
     * @param array<int, string>   $responses  status code => description
     * @param array<int, Parameter> $parameters
     */
    private function get(string $operationId, string $summary, string $description, array $responses, array $parameters = []): PathItem
    {
        $operation = new Operation(
            operationId: $operationId,
            tags: [self::TAG],
            summary: $summary,
            description: $description,
            parameters: $parameters,
            security: [['JWT' => []]],
        );

        foreach ($responses as $status => $responseDescription) {
            $operation = $operation->withResponse($status, new Response($responseDescription));
        }

        return (new PathItem())->withGet($operation);
    }
}
