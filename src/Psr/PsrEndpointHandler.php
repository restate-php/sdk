<?php

declare(strict_types=1);

namespace Restate\Psr;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Restate\Endpoint;
use Restate\Internal\HeaderMap;

final readonly class PsrEndpointHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $endpointResponse = $this->endpoint->handleHttpRequest(self::requestUri($request), HeaderMap::fromLists($request->getHeaders()), (string) $request->getBody());
        $response = $this->responseFactory->createResponse($endpointResponse->statusCode);
        foreach ($endpointResponse->headers as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }
        $response->getBody()->write($endpointResponse->body);

        return $response;
    }

    private static function requestUri(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $query = $uri->getQuery();

        return $query === '' ? $path : "{$path}?{$query}";
    }
}
