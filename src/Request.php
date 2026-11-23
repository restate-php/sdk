<?php

declare(strict_types=1);

namespace Restate;

/**
 * The request that started the current invocation, as Restate passed it to this handler ({@see Context::request()}).
 * The same for every attempt of the invocation, except for the attempt headers.
 *
 * Restate sends the idempotency key, scope and limit key from service protocol v7 (Restate 1.8) on; with older
 * servers they are always null.
 */
final readonly class Request
{
    /**
     * The invocation's headers, as the caller sent them to Restate, with lowercase names.
     *
     * @var array<string, string>
     */
    public array $headers;

    /**
     * The HTTP headers of the request Restate sent to this endpoint for the current attempt, with lowercase names. They
     * change from attempt to attempt (for example the W3C trace context), so handler logic must not depend on them
     * deterministically.
     *
     * @var array<string, string>
     */
    public array $attemptHeaders;

    /**
     * @param string $id the invocation id: unique per invocation, the same across retries and suspensions
     * @param Target $target the service, handler and (for virtual objects and workflows) key that was invoked
     * @param array<string, string> $headers header names are lowercased; of names that differ only in case, the last
     *                                       one wins
     * @param array<string, string> $attemptHeaders lowercased like $headers
     * @param string $body the raw input bytes, before the handler's input serializer and any Journal Value Codec
     * @param ?string $idempotencyKey the idempotency key the invocation was submitted with, if any
     * @param ?string $scope the scope the invocation was submitted with, if any
     * @param ?string $limitKey the limit key the invocation was submitted with, if any
     *
     * @internal built by the endpoint for each invocation, which hands it to handlers ({@see Context::request()}) and
     *           service factories
     */
    public function __construct(
        public string $id,
        public Target $target,
        array $headers,
        array $attemptHeaders,
        public string $body,
        public ?string $idempotencyKey = null,
        public ?string $scope = null,
        public ?string $limitKey = null,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->attemptHeaders = array_change_key_case($attemptHeaders, CASE_LOWER);
    }

    /** The value of an invocation header (not an attempt header), looked up ignoring case. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
