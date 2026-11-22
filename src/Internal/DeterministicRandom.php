<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;

/**
 * The pseudo-random generator behind a context's `randInt()`, `randBytes()` and `uuid()`, seeded from the invocation.
 *
 * @internal
 */
final class DeterministicRandom
{
    private const int MAX_UINT32 = 0xffffffff;

    private const int UINT32_RANGE = 4294967296;

    private int $hi;

    private int $lo;

    /**
     * @param int $seed Signed PHP integer seed. Rust unsigned 64-bit seeds outside PHP's integer domain must be normalized before construction.
     */
    public function __construct(int $seed)
    {
        $this->hi = ($seed >> 32) & 0xffffffff;
        $this->lo = $seed & 0xffffffff;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException('Random integer minimum must be less than or equal to maximum.');
        }
        if ($min === $max) {
            return $min;
        }

        $range = self::inclusiveRangeSize($min, $max);

        return $min + ($this->nextUInt32() % $range);
    }

    public function nextBytes(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Random byte length must be greater than or equal to 0.');
        }

        $bytes = '';
        while (strlen($bytes) < $length) {
            $bytes .= pack('N', $this->nextUInt32());
        }

        return substr($bytes, 0, $length);
    }

    private function nextUInt32(): int
    {
        [$this->hi, $this->lo] = self::add64($this->hi, $this->lo, 0x9e3779b9, 0x7f4a7c15);
        [$zHi, $zLo] = [$this->hi, $this->lo];
        [$zHi, $zLo] = self::xorShiftRight($zHi, $zLo, 30);
        [$zHi, $zLo] = self::mul64($zHi, $zLo, 0xbf58476d, 0x1ce4e5b9);
        [$zHi, $zLo] = self::xorShiftRight($zHi, $zLo, 27);
        [$zHi, $zLo] = self::mul64($zHi, $zLo, 0x94d049bb, 0x133111eb);
        [$zHi, $zLo] = self::xorShiftRight($zHi, $zLo, 31);

        return $zHi;
    }

    private static function inclusiveRangeSize(int $min, int $max): int
    {
        if ($min >= 0 || $max < 0) {
            $distance = $max - $min;
            if ($distance >= self::UINT32_RANGE) {
                throw new InvalidArgumentException('Random integer range must fit within 32 bits.');
            }

            return $distance + 1;
        }

        if ($min < -self::MAX_UINT32) {
            throw new InvalidArgumentException('Random integer range must fit within 32 bits.');
        }

        $negativeDistance = -$min;
        if ($max > self::MAX_UINT32 - $negativeDistance) {
            throw new InvalidArgumentException('Random integer range must fit within 32 bits.');
        }

        return $negativeDistance + $max + 1;
    }

    /** @return array{0: int, 1: int} */
    private static function add64(int $hi, int $lo, int $addHi, int $addLo): array
    {
        $loSum = $lo + $addLo;
        $newLo = $loSum & 0xffffffff;
        $carry = $loSum >> 32;
        $newHi = ($hi + $addHi + $carry) & 0xffffffff;

        return [$newHi, $newLo];
    }

    /** @return array{0: int, 1: int} */
    private static function xorShiftRight(int $hi, int $lo, int $shift): array
    {
        $shiftedHi = $hi >> $shift;
        $shiftedLo = (($hi << (32 - $shift)) | ($lo >> $shift)) & 0xffffffff;

        return [($hi ^ $shiftedHi) & 0xffffffff, ($lo ^ $shiftedLo) & 0xffffffff];
    }

    /** @return array{0: int, 1: int} */
    private static function mul64(int $hi, int $lo, int $mulHi, int $mulLo): array
    {
        $a0 = $lo & 0xffff;
        $a1 = $lo >> 16;
        $a2 = $hi & 0xffff;
        $a3 = $hi >> 16;
        $b0 = $mulLo & 0xffff;
        $b1 = $mulLo >> 16;
        $b2 = $mulHi & 0xffff;
        $b3 = $mulHi >> 16;

        $sum = $a0 * $b0;
        $p0 = $sum & 0xffff;
        $carry = $sum >> 16;

        $sum = $carry + ($a0 * $b1) + ($a1 * $b0);
        $p1 = $sum & 0xffff;
        $carry = $sum >> 16;

        $sum = $carry + ($a0 * $b2) + ($a1 * $b1) + ($a2 * $b0);
        $p2 = $sum & 0xffff;
        $carry = $sum >> 16;

        $sum = $carry + ($a0 * $b3) + ($a1 * $b2) + ($a2 * $b1) + ($a3 * $b0);
        $p3 = $sum & 0xffff;

        return [($p2 | ($p3 << 16)) & 0xffffffff, ($p0 | ($p1 << 16)) & 0xffffffff];
    }
}
