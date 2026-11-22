<?php

declare(strict_types=1);

namespace Restate;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Restate\Internal\Backend\Extension\Backend as ExtensionBackend;
use Restate\Internal\BackendException;
use Restate\Internal\ClosureErrorMapper;
use Restate\Internal\CoreLogForwarder;
use Restate\Internal\IdentityKeys;
use Restate\Internal\JournalValueCodecResolver;
use Restate\Internal\SerdeResolver;
use Restate\Reflection\ServiceReflector;

final class EndpointBuilder
{
    /** @var list<object|array{0: class-string, 1: (callable(Request): object)|null}> services, or service classes with their factory */
    private array $services = [];

    private ?ContainerInterface $container = null;

    private JsonPayloadOptions $jsonPayloads;

    private ?Serde $defaultSerde = null;

    private ?JournalValueCodec $journalValueCodec = null;

    /** @var list<mixed>|null null: not configured, so requests are not verified */
    private ?array $identityKeys = null;

    /** @var list<HandlerInterceptor|RunInterceptor> */
    private array $interceptors = [];

    private ?ErrorMapper $errorMapper = null;

    private ?LoggerInterface $logger = null;

    private ?string $coreLogLevel = LogLevel::INFO;

    public function __construct()
    {
        $this->jsonPayloads = JsonPayloadOptions::defaults();
    }

    /**
     * Adds a service: a builder (`Service`, `VirtualObject`, `Workflow`), a `ServiceDefinition`, an attributed service
     * object, or an attributed service class. The handlers of a class run on an instance created for every invocation
     * attempt: by `$factory`, which gets the invocation's {@see Request}, or else by the endpoint's {@see container()}
     * (see {@see ServiceReflector::fromClass()}).
     *
     * @param object|class-string $service
     * @param (callable(Request): object)|null $factory only for a service class
     */
    public function bind(object|string $service, ?callable $factory = null): self
    {
        if (is_object($service) && $factory !== null) {
            throw new \InvalidArgumentException(sprintf('A factory is only for a service class, not for a %s instance.', $service::class));
        }

        $clone = clone $this;
        $clone->services[] = is_string($service) ? [$service, $factory] : $service;

        return $clone;
    }

    /**
     * Adds services as {@see bind()} does, service classes without a factory (they come from the {@see container()}).
     *
     * @param iterable<object|class-string> $services
     */
    public function services(iterable $services): self
    {
        $clone = $this;
        foreach ($services as $service) {
            $clone = $clone->bind($service);
        }

        return $clone === $this ? clone $this : $clone;
    }

    /**
     * The PSR-11 container that provides the instances of service classes bound without a factory: the endpoint calls
     * `get($class)` once per invocation attempt, so a container that creates a new object per `get()` (or is reset
     * between requests) scopes the service's dependencies to the invocation.
     */
    public function container(ContainerInterface $container): self
    {
        $clone = clone $this;
        $clone->container = $container;

        return $clone;
    }

    public function jsonPayloads(JsonPayloadOptions $jsonPayloads): self
    {
        $clone = clone $this;
        $clone->jsonPayloads = $jsonPayloads;

        return $clone;
    }

    /** @param Serde|class-string<Serde> $serde */
    public function defaultSerde(Serde|string $serde): self
    {
        $clone = clone $this;
        $clone->defaultSerde = SerdeResolver::resolve($serde);

        return $clone;
    }

    /** @param JournalValueCodec|class-string<JournalValueCodec> $codec */
    public function journalValueCodec(JournalValueCodec|string $codec): self
    {
        $clone = clone $this;
        $clone->journalValueCodec = JournalValueCodecResolver::resolve($codec);

        return $clone;
    }

    /**
     * Adds Runtime Execution Hooks for every service of the endpoint, after the ones added before: the first one added
     * is the outermost, and the endpoint's hooks wrap each service's own. An object implementing both interfaces wraps
     * handlers and runs.
     */
    public function interceptor(HandlerInterceptor|RunInterceptor ...$interceptors): self
    {
        $clone = clone $this;
        $clone->interceptors = [...$this->interceptors, ...array_values($interceptors)];

        return $clone;
    }

    /**
     * Sets the endpoint's error mapper, asked when a service has none or its own returns null.
     *
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|PauseError|null) $errorMapper
     */
    public function errorMapper(ErrorMapper|\Closure $errorMapper): self
    {
        $clone = clone $this;
        $clone->errorMapper = ClosureErrorMapper::of($errorMapper);

        return $clone;
    }

    /**
     * The PSR-3 logger the SDK logs its own work to: failed, paused and suspended attempts, terminal failures, failed
     * `ctx.run` callbacks, rejected and failed requests (docs/decisions/0027-sdk-logging.md). Handler code logs to it through
     * {@see Context::logger()}, which drops records while the handler replays. Without one, nothing is logged.
     *
     * The extension's state machine (`restate-sdk-shared-core`) logs too, mostly at debug: a journal mismatch, each
     * suspension, each command it executes. Its records at `$coreLogLevel` or more severe go to the logger as well (null:
     * none). Capturing them costs a little on every VM call, so only lower it to debug while you investigate.
     *
     * @param ?string $coreLogLevel a {@see LogLevel} constant, or null
     *
     * @throws \InvalidArgumentException for a level PSR-3 does not define
     */
    public function logger(LoggerInterface $logger, ?string $coreLogLevel = LogLevel::INFO): self
    {
        if ($coreLogLevel !== null) {
            CoreLogForwarder::coreLevel($coreLogLevel);
        }

        $clone = clone $this;
        $clone->logger = $logger;
        $clone->coreLogLevel = $coreLogLevel;

        return $clone;
    }

    /**
     * Verifies that every invocation and discovery request is signed by a Restate instance holding the private key of
     * one of these `publickeyv1_…` keys. Without this call the endpoint accepts unsigned requests.
     *
     * Configuring no key is a mistake, not a way to turn verification off: {@see build()} rejects an empty list, an
     * empty key and `false` (what `getenv()` returns for a variable that is not set) with `InvalidArgumentException`,
     * so a missing key fails closed.
     *
     * @param iterable<mixed> $identityKeys
     */
    public function identityKeys(iterable $identityKeys): self
    {
        $clone = clone $this;
        $clone->identityKeys = is_array($identityKeys) ? array_values($identityKeys) : iterator_to_array($identityKeys, false);

        return $clone;
    }

    /**
     * @throws \InvalidArgumentException when the configuration is invalid, for example identity keys that are empty or `false`
     * @throws BackendUnavailableException when the Restate PHP extension is not loaded or does not match this package
     */
    public function build(): Endpoint
    {
        // Configuration errors first: they do not depend on the extension.
        $services = $this->normalizeServices($this->services);
        $identityKeys = IdentityKeys::of($this->identityKeys);

        try {
            $backend = new ExtensionBackend();
        } catch (BackendException $e) {
            throw new BackendUnavailableException($e->getMessage(), previous: $e);
        }

        return Endpoint::create($backend, $services, jsonPayloads: $this->jsonPayloads, identityKeys: $identityKeys, defaultSerde: $this->defaultSerde, journalValueCodec: $this->journalValueCodec, interceptors: $this->interceptors, errorMapper: $this->errorMapper, logger: $this->logger, coreLogLevel: $this->coreLogLevel);
    }

    /**
     * @param list<object|array{0: class-string, 1: (callable(Request): object)|null}> $services
     * @return list<Service|VirtualObject|Workflow|ServiceDefinition>
     */
    private function normalizeServices(array $services): array
    {
        return array_map(
            fn (object|array $service): Service|VirtualObject|Workflow|ServiceDefinition => match (true) {
                is_array($service) => ServiceReflector::fromClass(
                    $service[0],
                    $service[1] ?? $this->container ?? throw new \InvalidArgumentException("Service class {$service[0]} needs a factory (bind(\$class, \$factory)) or a container (container(\$container)) to be instantiated."),
                ),
                $service instanceof Service, $service instanceof VirtualObject, $service instanceof Workflow, $service instanceof ServiceDefinition => $service,
                default => ServiceReflector::fromObject($service),
            },
            $services,
        );
    }
}
