<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\NumericType;

/** Immutable PDF affine transform: x'=ax+cy+e, y'=bx+dy+f. */
final readonly class ContentMatrix
{
    public function __construct(public float $a = 1, public float $b = 0, public float $c = 0, public float $d = 1, public float $e = 0, public float $f = 0)
    {
        foreach ([$a, $b, $c, $d, $e, $f] as $number) {
            if (!is_finite($number)) {
                throw new ReadingException('A content transformation must have finite coefficients.');
            }
        }
        $determinant = $a * $d - $b * $c;
        if (!is_finite($determinant) || $determinant === 0.0) {
            throw new ReadingException('A degenerate or overflowing content transformation is not supported.');
        }
    }
    /** @param list<BasicType> $operands */
    public static function operands(array $operands): self
    {
        if (count($operands) !== 6) {
            throw new ReadingException('An affine transformation requires six numeric coefficients.');
        }
        $numbers = [];
        foreach ($operands as $operand) {
            if (!$operand instanceof NumericType) {
                throw new ReadingException('An affine transformation requires numeric coefficients.');
            }
            $numbers[] = (float) $operand->value;
        }
        return new self($numbers[0], $numbers[1], $numbers[2], $numbers[3], $numbers[4], $numbers[5]);
    }
    /** PDF concatenation applies the new local transform before the current transform. */
    public function concatenate(self $local): self
    {
        return new self($this->a * $local->a + $this->c * $local->b, $this->b * $local->a + $this->d * $local->b, $this->a * $local->c + $this->c * $local->d, $this->b * $local->c + $this->d * $local->d, $this->a * $local->e + $this->c * $local->f + $this->e, $this->b * $local->e + $this->d * $local->f + $this->f);
    }
    /** @return array{float, float} */
    public function unitDimensions(): array
    {
        $width = hypot($this->a, $this->b);
        $height = hypot($this->c, $this->d);
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0 || $width > PHP_INT_MAX / 2 || $height > PHP_INT_MAX / 2) {
            throw new ReadingException('Logical image dimensions are degenerate or exceed the integer pixel conversion range.');
        }
        return [$width, $height];
    }
    /**
     * Translation does not affect the logical edge lengths retained by derivation.
     * @return array{float, float, float, float}
     */
    public function linear(): array
    {
        return [$this->a, $this->b, $this->c, $this->d];
    }
}
