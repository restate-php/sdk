<?php

declare(strict_types=1);

namespace Restate;

final readonly class ObjectClient
{
    /**
     * @param ?string $scope the scope its targets are in ({@see Context::scope()}); null for none
     *
     * @internal built by {@see Context::objectClient()} and {@see Scope::objectClient()}
     */
    public function __construct(
        private readonly Context $context,
        private readonly string $object,
        private readonly string $key,
        private readonly ?string $scope = null,
    ) {
    }

    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::object($this->object, $this->key, $handler);

        return new ClientRequest($this->context, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
