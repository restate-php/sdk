<?php

declare(strict_types=1);

namespace Restate;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Restate\Internal\Backend;
use Restate\Internal\Backend\IdentityVerifier;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\ClosureErrorMapper;
use Restate\Internal\CoreLogForwarder;
use Restate\Internal\Discovery;
use Restate\Internal\HeaderMap;
use Restate\Internal\IdentityKeys;
use Restate\Internal\InvocationAttempt;
use Restate\Internal\OutputCapture;
use Restate\Internal\Route;
use Restate\Internal\RouteKind;
use Restate\Internal\SdkLog;
use Restate\Internal\ServiceRegistry;
use Restate\Internal\StrayOutput;
use Restate\Internal\SuspensionException;
use Restate\Internal\SuspensionGuard;
use Restate\Internal\Utf8;

/**
 * A Restate service endpoint: answers discovery, health checks and invocations for its services.
 *
 * Build one with {@see builder()} or {@see fromServices()}, or serve directly with {@see serve()} or {@see psr()}.
 */
final readonly class Endpoint
{
    /** Where output started when `headers_sent()` names no file: PHP printed it before the script ran. */
    private const string OUTPUT_BEFORE_SCRIPT = 'before the script ran: a PHP startup warning, such as a request body over post_max_size, or an auto_prepend_file';

    private const string OUTPUT_FIX = 'Set display_errors=Off with log_errors=On so PHP never prints warnings into responses (display_errors=stderr still prints into responses under php -S), and enable_post_data_reading=0 so PHP does not check request bodies against post_max_size.';

    private readonly ServiceRegistry $registry;

    /** Null when no identity keys are configured: every request is accepted. */
    private readonly ?IdentityVerifier $identityVerifier;

    /**
     * @param iterable<Service|VirtualObject|Workflow|ServiceDefinition> $services
     * @param iterable<mixed>|null $identityKeys null: not configured
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors
     * @param 'error'|'warn'|'info'|'debug'|null $coreLogLevel the level to capture shared-core's log events at; null: none
     */
    private function __construct(
        private readonly Backend $backend,
        iterable $services,
        ?JsonPayloadOptions $jsonPayloads,
        ?iterable $identityKeys,
        ?Serde $defaultSerde,
        ?JournalValueCodec $journalValueCodec,
        array $interceptors,
        ?ErrorMapper $errorMapper,
        private LoggerInterface $logger,
        private ?string $coreLogLevel,
    ) {
        $this->registry = new ServiceRegistry($services, $jsonPayloads ?? JsonPayloadOptions::defaults(), defaultSerde: $defaultSerde, journalValueCodec: $journalValueCodec, interceptors: $interceptors, errorMapper: $errorMapper);
        $keys = IdentityKeys::of($identityKeys);
        $this->identityVerifier = $keys === null ? null : $backend->identityVerifier($keys);
    }

    /**
     * @internal {@see EndpointBuilder::build()} creates endpoints with the extension backend, tests with a fake one.
     *
     * @param iterable<Service|VirtualObject|Workflow|ServiceDefinition> $services
     * @param iterable<mixed>|null $identityKeys null: not configured, so requests are not verified; an empty list, an empty key or `false` throws
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|PauseError|null)|null $errorMapper
     * @param ?LoggerInterface $logger where the SDK logs its own work (docs/decisions/0027-sdk-logging.md); null: nowhere
     * @param ?string $coreLogLevel the PSR-3 level from which shared-core's own log events go to $logger too; null: none
     */
    public static function create(
        Backend $backend,
        iterable $services,
        ?JsonPayloadOptions $jsonPayloads = null,
        ?iterable $identityKeys = null,
        ?Serde $defaultSerde = null,
        ?JournalValueCodec $journalValueCodec = null,
        array $interceptors = [],
        ErrorMapper|\Closure|null $errorMapper = null,
        ?LoggerInterface $logger = null,
        ?string $coreLogLevel = LogLevel::INFO,
    ): self {
        $logger ??= new NullLogger();
        $coreLogLevel = $coreLogLevel === null ? null : CoreLogForwarder::coreLevel($coreLogLevel);

        return new self($backend, $services, $jsonPayloads, $identityKeys, $defaultSerde, $journalValueCodec, $interceptors, $errorMapper === null ? null : ClosureErrorMapper::of($errorMapper), $logger, $logger instanceof NullLogger ? null : $coreLogLevel);
    }

    public static function builder(): EndpointBuilder
    {
        return new EndpointBuilder();
    }

    /**
     * @param iterable<object> $services
     * @param iterable<mixed>|null $identityKeys see {@see EndpointBuilder::identityKeys()}; null: requests are not verified
     */
    public static function fromServices(iterable $services, ?iterable $identityKeys = null, ?LoggerInterface $logger = null): self
    {
        $builder = self::builder()->services($services);
        $builder = $identityKeys === null ? $builder : $builder->identityKeys($identityKeys);

        return ($logger === null ? $builder : $builder->logger($logger))->build();
    }

    /**
     * @param iterable<object> $services
     * @param iterable<mixed>|null $identityKeys see {@see EndpointBuilder::identityKeys()}; null: requests are not verified
     */
    public static function serve(iterable $services, ?iterable $identityKeys = null, ?LoggerInterface $logger = null): void
    {
        $body = file_get_contents('php://input');
        self::fromServices($services, $identityKeys, $logger)->handle($_SERVER, (string) $body);
    }

    /**
     * @param iterable<object> $services
     * @param iterable<mixed>|null $identityKeys see {@see EndpointBuilder::identityKeys()}; null: requests are not verified
     */
    public static function psr(iterable $services, \Psr\Http\Message\ResponseFactoryInterface $responseFactory, ?iterable $identityKeys = null, ?LoggerInterface $logger = null): Psr\PsrEndpointHandler
    {
        return new Psr\PsrEndpointHandler(self::fromServices($services, $identityKeys, $logger), $responseFactory);
    }

    /**
     * Never throws. Once the invocation's VM exists, a failure is reported to it, and the response carries the VM's
     * head and whatever output it produced. Any other failure, including one while reporting to the VM, becomes a
     * JSON error response with status 500, as in the official SDKs.
     *
     * @param array<string, mixed> $server
     */
    public function handleRequest(array $server, string $body): EndpointResponse
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');
        try {
            $headers = HeaderMap::fromServer($server);
        } catch (\Throwable $e) {
            return $this->internalServerError($e, $requestUri, []);
        }

        return $this->handleHttpRequest($requestUri, $headers, $body);
    }

    /**
     * {@see handleRequest()} for a request that is not in `$_SERVER` form, so its header names reach the endpoint as
     * sent rather than through `HTTP_*` keys (which turn `_` into `-` and merge names that differ only there).
     *
     * @internal {@see Psr\PsrEndpointHandler} calls it with the PSR-7 request's headers.
     *
     * @param string $requestUri the request target: path and optional query
     * @param list<array{0: string, 1: string}> $headers name/value pairs, names lowercase, values UTF-8
     */
    public function handleHttpRequest(string $requestUri, array $headers, string $body): EndpointResponse
    {
        // Whatever PHP prints meanwhile (an echo, a displayed warning or deprecation) would land in front of the
        // protocol response and corrupt it: capture it, discard it and log it (docs/decisions/0032-stray-output.md).
        $output = OutputCapture::start();
        try {
            try {
                return $this->route($requestUri, $headers, $body);
            } catch (\Throwable $e) {
                return $this->internalServerError($e, $requestUri, $headers);
            }
        } finally {
            $stray = $output->stop();
            if ($stray !== null) {
                $this->logStrayOutput($stray, $requestUri, $headers);
            }
        }
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function logStrayOutput(StrayOutput $output, string $requestUri, array $headers): void
    {
        $context = SdkLog::request($requestUri, $headers) + $output->logContext();
        if (!$output->discarded) {
            $this->log(LogLevel::ERROR, 'Request code ended the output buffer the endpoint discards stray output with (an unbalanced ob_end_*() or ob_get_clean() call); output printed after that corrupts the response', $context);
        } else {
            $this->log(LogLevel::WARNING, 'Discarded output printed while handling the request, which would have corrupted the response; log instead of printing, and set display_errors=Off with log_errors=On', $context);
        }
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function internalServerError(\Throwable $e, string $requestUri, array $headers): EndpointResponse
    {
        $this->log(LogLevel::ERROR, 'Failed to handle the request', SdkLog::request($requestUri, $headers) + ['exception' => $e]);

        return $this->errorResponse(500, $e->getMessage());
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        SdkLog::write($this->logger, $level, $message, $context);
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function route(string $requestUri, array $headers, string $body): EndpointResponse
    {
        $accept = array_find($headers, static fn (array $header): bool => $header[0] === 'accept')[1] ?? '';
        $route = Route::parse($requestUri, $accept);

        if ($route->kind === RouteKind::Health) {
            return $this->jsonResponse(200, ['status' => 'ok']);
        }

        if ($route->kind === RouteKind::Unknown) {
            return new EndpointResponse(404, [], '');
        }

        // Like the official SDKs, discovery is identity-checked too: Restate signs it with audience /discover.
        $identityError = $this->identityVerifier?->verify($headers, self::path($requestUri));
        if ($identityError !== null) {
            $this->log(LogLevel::ERROR, 'Rejected a request without a valid Restate request identity', SdkLog::request($requestUri, $headers) + ['restate.identity.error' => $identityError]);

            return $this->errorResponse(401, 'Invalid Restate request identity.');
        }

        if ($route->kind === RouteKind::Discover) {
            return $this->discover($accept, $requestUri, $headers);
        }

        // The content type names the service protocol version. Like the TypeScript SDK, require it before creating
        // the VM, which would otherwise reject the request with a bare message.
        if (array_find($headers, static fn (array $header): bool => $header[0] === 'content-type') === null) {
            return $this->rejected(415, 'Missing content-type header', $requestUri, $headers);
        }

        $coreLogs = $this->coreLogLevel === null ? null : new CoreLogForwarder($this->logger, $this->coreLogLevel, SdkLog::beforeInput($headers, $route->service, $route->handler));
        $vm = $this->backend->newInvocation($headers, $coreLogs);
        try {
            return $this->respond($vm, $route->service, $route->handler, $body, $headers, $coreLogs);
        } finally {
            // Release the VM now rather than whenever PHP destroys the last reference (a long-lived worker may keep
            // the handler's context, or an exception holding it, alive).
            $vm->free();
        }
    }

    /** The request URI's path, `/` when it has none; bytes that are not UTF-8 are replaced by U+FFFD, as in headers. */
    private static function path(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? Utf8::substitute($path) : '/';
    }

    /**
     * Answers with the newest manifest version both sides know, advertising the service protocol versions the
     * backend's VM negotiates. Like the TypeScript SDK, a request without a version this SDK serves gets a 415.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private function discover(string $accept, string $requestUri, array $headers): EndpointResponse
    {
        $manifestVersion = Discovery::manifestVersion($accept);
        if ($manifestVersion === null) {
            return $this->rejected(415, $accept === '' ? 'Missing accept header' : "Unsupported service discovery protocol version '{$accept}'", $requestUri, $headers);
        }

        return new EndpointResponse(
            200,
            [['content-type', Discovery::contentType($manifestVersion)]],
            Discovery::manifestJson($this->registry, $this->backend->protocolVersions(), $manifestVersion),
        );
    }

    /**
     * An error response to a request Restate should not have sent (the TypeScript SDK logs these as warnings too).
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private function rejected(int $status, string $message, string $requestUri, array $headers): EndpointResponse
    {
        $this->log(LogLevel::WARNING, $message, SdkLog::request($requestUri, $headers));

        return $this->errorResponse($status, $message);
    }

    /**
     * Runs the attempt on the VM and builds the response from the VM's head and output.
     *
     * @param list<array{0: string, 1: string}> $headers the attempt's HTTP request headers
     */
    private function respond(Invocation $vm, string $service, string $handler, string $body, array $headers, ?CoreLogForwarder $coreLogs): EndpointResponse
    {
        $head = $vm->responseHead();

        $operations = new SuspensionGuard($vm);
        $logContext = SdkLog::beforeInput($headers, $service, $handler);
        try {
            $this->execute($vm, $operations, $service, $handler, $body, $headers, $logContext, $coreLogs);
        } catch (\Throwable $e) {
            // Once the invocation suspended, the core has already written the suspension and closed: whatever the
            // handler throws afterwards (it may have caught the SuspensionException) is ignored.
            if ($operations->isSuspended() || $e instanceof SuspensionException) {
                $this->log(LogLevel::DEBUG, 'Invocation suspended', $logContext);
            } elseif ($e instanceof PauseError) {
                $this->log(LogLevel::WARNING, 'Invocation paused; it waits until it is resumed', $logContext + ['exception' => $e]);
                $vm->notifyPause($e->getMessage(), $e->getTraceAsString());
            } else {
                $this->log(LogLevel::WARNING, 'Invocation attempt failed; Restate retries it', $logContext + ['exception' => $e]);
                // A RetryableError reports its status code (its metadata has no place in the protocol's error message);
                // anything else is 500.
                if ($e instanceof RetryableError) {
                    $vm->notifyError($e->getMessage(), $e->getTraceAsString(), $e->retryAfterMillis(), $e->statusCode());
                } else {
                    $vm->notifyError($e->getMessage(), $e->getTraceAsString());
                }
            }
        }

        $responseBody = '';
        while (($chunk = $vm->takeOutput()) !== null) {
            $responseBody .= $chunk;
        }

        return new EndpointResponse($head->statusCode, $head->headers, $responseBody);
    }

    /**
     * Runs the attempt from input to output. The caller reports anything this throws to the VM, unless the
     * invocation suspended.
     *
     * @param list<array{0: string, 1: string}> $headers the attempt's HTTP request headers
     * @param array<string, string> $logContext the context of the attempt's log records; set once the input is read
     */
    private function execute(Invocation $vm, SuspensionGuard $operations, string $service, string $handler, string $body, array $headers, array &$logContext, ?CoreLogForwarder $coreLogs): void
    {
        $vm->notifyInput($body);
        $vm->notifyInputClosed();

        if (!$vm->isReadyToExecute()) {
            throw new \RuntimeException('Restate invocation did not become ready after request-response input closed');
        }

        $input = $vm->input();
        $logContext = SdkLog::of($input->invocationId, SdkLog::target($service, $input->key, $handler));
        $coreLogs?->setContext($logContext);
        // Each attempt is logged once, replaying or not (as the official SDKs do); only handler code is muted on replay.
        // Without a logger, spare the VM the question.
        if (!$this->logger instanceof NullLogger) {
            $this->log(LogLevel::DEBUG, $operations->isReplaying() ? 'Replaying invocation' : 'Starting invocation', $logContext);
        }
        $attempt = InvocationAttempt::of($operations, $input, $headers, $this->logger);

        try {
            $output = $this->registry->invoke($service, $handler, $input->input, $attempt, $input->key);
        } catch (TerminalError $e) {
            if ($operations->isSuspended()) {
                $this->log(LogLevel::DEBUG, 'Invocation suspended', $logContext);
            } else {
                $this->log(LogLevel::INFO, 'Invocation failed with a terminal error', $logContext + ['exception' => $e]);
                $vm->writeOutputFailure($e->statusCode(), $e->getMessage(), $e->metadata());
                $vm->end();
            }

            return;
        }

        // A handler that caught the suspension may still return normally; its result is ignored.
        if ($operations->isSuspended()) {
            $this->log(LogLevel::DEBUG, 'Invocation suspended', $logContext);
        } else {
            $vm->writeOutputSuccess($output);
            $vm->end();
            $this->log(LogLevel::DEBUG, 'Invocation completed', $logContext);
        }
    }

    /**
     * Handles the request in `$_SERVER` form and sends the response through PHP's SAPI: status, headers and body.
     *
     * Output PHP sent before this call cannot be taken back: the response has gone out as whatever PHP sent (`200`,
     * `text/html`), and Restate rejects it as not a protocol response and retries the attempt. The endpoint then does
     * not run the request at all (its `ctx.run` side effects would repeat on every retry without ever being journaled),
     * logs an error naming where the output started and how to fix it, and answers with that diagnostic as plain text.
     * Restate shows a discovery response's body when registration fails. Earlier output that is still in PHP's own
     * output buffer is discarded and logged (docs/decisions/0032-stray-output.md).
     *
     * @param array<string, mixed> $server
     */
    public function handle(array $server, string $body): void
    {
        if (headers_sent($file, $line)) {
            $origin = $file === '' ? self::OUTPUT_BEFORE_SCRIPT : "{$file}:{$line}";
            $this->log(LogLevel::ERROR, 'PHP sent output before the endpoint could respond, so Restate cannot read the response and retries the attempt; the endpoint did not run it. ' . self::OUTPUT_FIX, self::serverLogContext($server) + [SdkLog::OUTPUT_ORIGIN => $origin]);
            echo "Restate endpoint error: PHP sent output before the endpoint could respond (output started {$origin}), so this is not a Restate protocol response and the request did not run. " . self::OUTPUT_FIX . "\n";

            return;
        }

        $buffered = self::discardBufferedOutput();
        if ($buffered !== null) {
            $this->log(
                $buffered->discarded ? LogLevel::WARNING : LogLevel::ERROR,
                ($buffered->discarded ? 'Discarded output PHP printed before the endpoint handled the request, which would have corrupted the response. ' : 'Output PHP printed before the endpoint handled the request is in an output buffer the endpoint cannot empty, and corrupts the response. ') . self::OUTPUT_FIX,
                self::serverLogContext($server) + $buffered->logContext(),
            );
        }

        $response = $this->handleRequest($server, $body);

        // Only when request code removed the output capture and printed past it, which the capture logged.
        if (!headers_sent()) {
            http_response_code($response->statusCode);
            foreach ($response->headers as [$name, $value]) {
                header("{$name}: {$value}", strtolower($name) === 'content-type');
            }
        }
        echo $response->body;
    }

    /**
     * Empties PHP's output buffer of what was printed before {@see handle()} and is still buffered, for example by an
     * `auto_prepend_file` under `output_buffering`. Only the innermost buffer can be emptied without removing it, and
     * only if it allows cleaning: output anywhere else stays, and is reported as not discarded.
     */
    private static function discardBufferedOutput(): ?StrayOutput
    {
        $buffers = ob_get_status(true);
        $bytes = array_sum(array_map(static fn (array $buffer): int => (int) $buffer['buffer_used'], $buffers));
        if ($bytes === 0) {
            return null;
        }

        $innermost = $buffers[count($buffers) - 1];
        $excerpt = substr((string) ob_get_contents(), 0, OutputCapture::EXCERPT_BYTES);
        $discarded = $bytes === (int) $innermost['buffer_used'] && ($innermost['flags'] & PHP_OUTPUT_HANDLER_CLEANABLE) !== 0 && ob_clean();

        return new StrayOutput($bytes, $excerpt, null, discarded: $discarded);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function serverLogContext(array $server): array
    {
        try {
            $headers = HeaderMap::fromServer($server);
        } catch (\Throwable) {
            $headers = [];
        }

        return SdkLog::request((string) ($server['REQUEST_URI'] ?? '/'), $headers);
    }

    private function errorResponse(int $status, string $message): EndpointResponse
    {
        return $this->jsonResponse($status, ['error' => $message]);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(int $status, array $payload): EndpointResponse
    {
        // Error messages may carry bytes that are not UTF-8; building the response must not fail on them.
        return new EndpointResponse($status, [['content-type', 'application/json']], json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
