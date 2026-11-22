<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;

final readonly class InvocationConfiguration
{
    /**
     * @param bool|list<string>|null $statePreload which state Restate sends with each invocation of a virtual object or
     *                                             workflow ("eager state"): true preloads all of it, false none (every
     *                                             read is a round trip to Restate), a list only those keys (the rest is
     *                                             fetched on first read; best-effort, within the server's eager state
     *                                             size limit). A non-empty list needs Restate 1.8 (discovery manifest
     *                                             v5); discovery fails on an older server. Null keeps the server's
     *                                             default (preload all). Not valid on a plain service.
     */
    public function __construct(
        public readonly ?int $inactivityTimeoutMillis = null,
        public readonly ?int $abortTimeoutMillis = null,
        public readonly ?int $journalRetentionMillis = null,
        public readonly ?int $idempotencyRetentionMillis = null,
        public readonly ?int $workflowCompletionRetentionMillis = null,
        public readonly bool|array|null $statePreload = null,
        public readonly ?bool $ingressPrivate = null,
    ) {
        foreach ([
            'inactivity timeout' => $inactivityTimeoutMillis,
            'abort timeout' => $abortTimeoutMillis,
            'journal retention' => $journalRetentionMillis,
            'idempotency retention' => $idempotencyRetentionMillis,
            'workflow completion retention' => $workflowCompletionRetentionMillis,
        ] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("Invocation configuration {$name} must be greater than or equal to 0 milliseconds.");
            }
        }

        if (is_array($statePreload)) {
            self::assertStatePreloadKeys($statePreload);
        }
    }

    /** @param array<array-key, mixed> $keys */
    private static function assertStatePreloadKeys(array $keys): void
    {
        if (!array_is_list($keys) || array_any($keys, static fn (mixed $key): bool => !is_string($key))) {
            throw new InvalidArgumentException('State preload keys must be a list of strings.');
        }
    }

    /** Whether this configuration preloads a list of keys, which needs discovery manifest v5. */
    public function preloadsStateKeys(): bool
    {
        return is_array($this->statePreload) && $this->statePreload !== [];
    }

    /** @return array<string, int|bool|list<string>> */
    public function manifestFields(): array
    {
        $fields = [];
        if ($this->inactivityTimeoutMillis !== null) {
            $fields['inactivityTimeout'] = $this->inactivityTimeoutMillis;
        }
        if ($this->abortTimeoutMillis !== null) {
            $fields['abortTimeout'] = $this->abortTimeoutMillis;
        }
        if ($this->journalRetentionMillis !== null) {
            $fields['journalRetention'] = $this->journalRetentionMillis;
        }
        if ($this->idempotencyRetentionMillis !== null) {
            $fields['idempotencyRetention'] = $this->idempotencyRetentionMillis;
        }
        if ($this->workflowCompletionRetentionMillis !== null) {
            $fields['workflowCompletionRetention'] = $this->workflowCompletionRetentionMillis;
        }
        if ($this->statePreload !== null) {
            // The manifest models a key list as lazy state plus a whitelist of keys still sent eagerly; an empty list
            // is plain lazy state, as in the TypeScript and Python SDKs.
            $fields['enableLazyState'] = $this->statePreload !== true;
            if ($this->preloadsStateKeys()) {
                $fields['eagerStateKeysWhitelist'] = $this->statePreload;
            }
        }
        if ($this->ingressPrivate !== null) {
            $fields['ingressPrivate'] = $this->ingressPrivate;
        }

        return $fields;
    }

    public function hasFields(): bool
    {
        return $this->manifestFields() !== [];
    }
}
