<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;
use Restate\JournalValueCodec;
use Restate\NoopJournalValueCodec;

/**
 * Resolves a journal value codec given as an instance or a class name.
 *
 * @internal
 */
final class JournalValueCodecResolver
{
    /** @param JournalValueCodec|class-string<JournalValueCodec> $codec */
    public static function resolve(JournalValueCodec|string $codec): JournalValueCodec
    {
        if ($codec instanceof JournalValueCodec) {
            return $codec;
        }

        if (!class_exists($codec)) {
            throw new InvalidArgumentException("Journal value codec class {$codec} does not exist.");
        }
        if (!is_subclass_of($codec, JournalValueCodec::class)) {
            throw new InvalidArgumentException("Journal value codec class {$codec} must implement " . JournalValueCodec::class . '.');
        }

        $reflection = new \ReflectionClass($codec);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new InvalidArgumentException("Journal value codec class {$codec} must be instantiable without constructor arguments.");
        }

        $instance = $reflection->newInstance();

        return $instance;
    }

    /** @param JournalValueCodec|class-string<JournalValueCodec>|null $codec */
    public static function resolveOrNoop(JournalValueCodec|string|null $codec): JournalValueCodec
    {
        return $codec === null ? new NoopJournalValueCodec() : self::resolve($codec);
    }
}
