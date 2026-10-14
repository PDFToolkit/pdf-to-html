<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

final readonly class ReadUnicodeMap
{
    /**
     * @param array<string, string> $mappings
     * @param list<array{string, string}> $spaces
     */
    public function __construct(public array $mappings, public array $spaces, private ReadingBudget $budget)
    {
    }
    public function decode(string $bytes): string
    {
        $result = '';
        for ($position = 0, $length = strlen($bytes); $position < $length;) {
            $matched = false;
            for ($size = 1; $size <= min(4, $length - $position); ++$size) {
                $code = substr($bytes, $position, $size);
                foreach ($this->spaces as [$low, $high]) {
                    $this->budget->consume('instructions');
                    if (strlen($low) === $size && strcmp($code, $low) >= 0 && strcmp($code, $high) <= 0) {
                        $mapped = $this->mappings[bin2hex($code)] ?? null;
                        if ($mapped === null) {
                            throw new ReadingException('A text character has no ToUnicode mapping.');
                        }
                        $this->budget->consume('text', strlen($mapped));
                        $result .= $mapped;
                        $position += $size;
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (!$matched) {
                throw new ReadingException('A text character is outside its ToUnicode codespace.');
            }
        }
        return $result;
    }
}
