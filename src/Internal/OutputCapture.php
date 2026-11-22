<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * Captures and discards what PHP prints while the endpoint handles a request: a handler's `echo`, or a warning,
 * notice or deprecation displayed with `display_errors` on. Printed output would land in front of the protocol
 * response and corrupt it (docs/decisions/0032-stray-output.md).
 *
 * The buffer flushes after every write (chunk size 1), so its callback runs where the output was printed and records
 * that place.
 *
 * @internal
 */
final class OutputCapture
{
    /** How much of the output a log record carries. */
    public const int EXCERPT_BYTES = 1024;

    private int $bytes = 0;

    private string $excerpt = '';

    /** `file:line` of the code that printed first, when PHP knows it. */
    private ?string $origin = null;

    private function __construct(private readonly int $level)
    {
    }

    public static function start(): self
    {
        $capture = new self(ob_get_level() + 1);
        ob_start($capture->discard(...), 1);

        return $capture;
    }

    /**
     * Ends the capture: closes any buffer the request left open above it, then its own. Null when nothing was printed
     * and the buffer stack is as it was.
     */
    public function stop(): ?StrayOutput
    {
        // Buffers handler code opened and did not close: their contents never reached this capture.
        while (ob_get_level() > $this->level) {
            $contents = ob_get_clean();
            if (is_string($contents)) {
                $this->record($contents, null);
            }
        }

        // Handler code closed this capture's buffer itself (an unbalanced ob_end_*()): output after that went past it.
        $removed = ob_get_level() < $this->level;
        if (!$removed) {
            ob_end_clean();
        }

        return $this->bytes === 0 && !$removed ? null : new StrayOutput($this->bytes, $this->excerpt, $this->origin, discarded: !$removed);
    }

    private function discard(string $buffer): string
    {
        if ($buffer !== '') {
            // The innermost frame that has a place: internal functions such as trigger_error() have none.
            $frame = $this->origin === null ? array_find(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static fn (array $frame): bool => isset($frame['file'], $frame['line'])) : null;
            $this->record($buffer, $frame === null ? null : "{$frame['file']}:{$frame['line']}");
        }

        return '';
    }

    private function record(string $output, ?string $origin): void
    {
        if ($output === '') {
            return;
        }

        $this->bytes += strlen($output);
        if (strlen($this->excerpt) < self::EXCERPT_BYTES) {
            $this->excerpt .= substr($output, 0, self::EXCERPT_BYTES - strlen($this->excerpt));
        }
        $this->origin ??= $origin;
    }
}
