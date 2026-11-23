<?php

declare(strict_types=1);

namespace Restate;

final readonly class ServiceClient
{
    /**
     * @param ?string $scope the scope its targets are in ({@see Context::scope()}); null for none
     *
     * @internal built by {@see Context::serviceClient()} and {@see Scope::serviceClient()}
     */
    public function __construct(
        private readonly Context $context,
        private readonly string $service,
        private readonly ?string $scope = null,
    ) {
    }

    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::service($this->service, $handler);

        return new ClientRequest($this->context, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
