<?php

declare(strict_types=1);

namespace PDF\Html\Html;

/** Ordered lexical declarations; quoted semicolons are data, never property boundaries. */
final readonly class CssDeclarations
{
    public function __construct(private string $text)
    {
    }

    /** @return \Generator<int, array{string, string}> */
    public function pairs(): \Generator
    {
        $part = '';
        $quote = null;
        $escaped = false;
        $length = strlen($this->text);
        for ($index = 0; $index <= $length; ++$index) {
            $char = $index === $length ? ';' : $this->text[$index];
            if ($index === $length || ($char === ';' && $quote === null && !$escaped)) {
                $pair = explode(':', $part, 2);
                if (count($pair) === 2) {
                    yield [trim($pair[0]), trim($pair[1])];
                } elseif (trim($part) !== '') {
                    yield [trim($part), ''];
                }
                $part = '';
                continue;
            }
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '/' && ($this->text[$index + 1] ?? '') === '*') {
                $end = strpos($this->text, '*/', $index + 2);
                if ($end === false) {
                    return;
                }
                $index = $end + 1;
                continue;
            }
            $part .= $char;
        }
    }
}
