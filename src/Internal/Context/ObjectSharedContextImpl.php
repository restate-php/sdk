<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Restate\Internal\ExecutionHooks;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\JournalValueCodec;
use Restate\ObjectSharedContext;
use Restate\Request;
use Restate\Serde;

/**
 * The {@see ObjectSharedContext} of a shared virtual object handler.
 *
 * @internal
 */
class ObjectSharedContextImpl extends ContextImpl implements ObjectSharedContext
{
    private readonly string $key;

    /**
     * @param Request $request its target carries the key
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public function __construct(InvocationOperations $vm, Request $request, int $randomSeed = 0, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null, ?ExecutionHooks $hooks = null, LoggerInterface $endpointLogger = new NullLogger())
    {
        parent::__construct($vm, $request, $randomSeed, $defaultSerde, $journalValueCodec, $hooks, $endpointLogger);

        $this->key = $request->target->key() ?? throw new \LogicException('A keyed context needs a request with a keyed target.');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function get(string $key, Serde|string|null $serde = null): mixed
    {
        $operations = $this->operations();

        return ResultDecoder::serde('state get', $this->serde($serde), $this->codec())->await($operations, $operations->sysGetState($key), 'State get suspended.');
    }

    public function stateKeys(): array
    {
        $operations = $this->operations();

        return ResultDecoder::stateKeys()->await($operations, $operations->sysGetStateKeys(), 'State keys suspended.');
    }
}
