<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * The result of {@see \Restate\Internal\InvocationOperations::doAwait()} and `doAwaitTree()`. Every state but
 * Completed leaves the awaited handles valid.
 *
 * @internal
 */
final readonly class AwaitResult
{
    /**
     * @param ?int $runHandle the run to execute, for ExecuteRun only
     * @param list<int> $completedHandles for doAwaitTree()'s Completed only: see {@see AwaitResult::$completedHandles}
     */
    private function __construct(
        public AwaitState $state,
        public ?int $runHandle = null,
        /**
         * Once doAwaitTree() completed, the handles of the tree that are then completed (their results processed and
         * not taken), in the tree's depth-first order, each as often as it occurs: what `isCompleted()` reports for
         * each handle right after the await. Empty for doAwait() and for every other state.
         *
         * @var list<int>
         */
        public array $completedHandles = [],
    ) {
    }

    /** @param list<int> $completedHandles for doAwaitTree() only: the tree's handles that are then completed */
    public static function completed(array $completedHandles = []): self
    {
        return new self(AwaitState::Completed, completedHandles: $completedHandles);
    }

    public static function suspended(): self
    {
        return new self(AwaitState::Suspended);
    }

    public static function cancelSignalReceived(): self
    {
        return new self(AwaitState::CancelSignalReceived);
    }

    public static function executeRun(int $handle): self
    {
        return new self(AwaitState::ExecuteRun, $handle);
    }

    /** Any state that carries no run handle and no completed handles. */
    public static function of(AwaitState $state): self
    {
        if ($state === AwaitState::ExecuteRun) {
            throw new \InvalidArgumentException('An execute-run await result needs the run handle: use executeRun().');
        }

        return new self($state);
    }

    public function isCompleted(): bool
    {
        return $this->state === AwaitState::Completed;
    }
}
