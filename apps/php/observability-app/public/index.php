<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    true,
    true,
    true
);

function jsonResponse(Response $response, array $data, int $status = 200): Response
{
    $payload = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    $response->getBody()->write($payload);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status);
}

$app->get('/', function (Request $request, Response $response): Response {
    return jsonResponse($response, [
        'application' => 'php-observability-demo',
        'framework' => 'Slim 4',
        'php_version' => PHP_VERSION,
        'status' => 'running',
        'endpoints' => [
            '/',
            '/health',
            '/users',
            '/slow',
            '/error'
        ]
    ]);
});

$app->get('/health', function (Request $request, Response $response): Response {
    return jsonResponse($response, [
        'status' => 'UP',
        'service' => 'php-observability-demo'
    ]);
});

$app->get('/users', function (Request $request, Response $response): Response {
    return jsonResponse($response, [
        'count' => 3,
        'users' => [
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
            ['id' => 3, 'name' => 'Linus']
        ]
    ]);
});

$app->get('/slow', function (Request $request, Response $response): Response {
    usleep(2_000_000);

    return jsonResponse($response, [
        'status' => 'OK',
        'message' => 'Resposta gerada após aproximadamente 2 segundos.',
        'delay_ms' => 2000
    ]);
});

$app->get('/error', function (Request $request, Response $response): Response {
    return jsonResponse($response, [
        'status' => 'ERROR',
        'message' => 'Erro HTTP 500 gerado propositalmente para o laboratório.'
    ], 500);
});

$app->run();
