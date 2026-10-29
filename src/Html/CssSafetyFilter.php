<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Diagnostic;

/** Bounded CSS subset: declarations and nested supports/media, without fetched resources. */
final class CssSafetyFilter
{
    /** @var list<Diagnostic> */
    public array $diagnostics = [];
    private int $bytes = 0;
    private int $tokens = 0;

    public function __construct(private readonly DerivationLimits $limits)
    {
    }

    /** @param array<string, string> $declarations
     * @return array<string, string> */
    public function declarations(array $declarations): array
    {
        $result = [];
        foreach ($declarations as $name => $value) {
            $this->consume($name);
            $this->consume($value);
            $this->token();
            if ($this->declarationAllowed($name, $value)) {
                $result[$name] = $value;
            }
        }
        return $result;
    }

    /** Preserve declaration order, including repeated browser fallback properties. */
    public function declarationText(string $text): string
    {
        $this->consume($text);
        return $this->declarationList($this->withoutComments($text) ?? '');
    }

    /** @param array<string, string> $overrides Explicit PDF CSS replaces the corresponding HTML style properties. */
    public function mergeDeclarations(string $text, array $overrides): string
    {
        $this->consume($text);
        $replaced = array_map($this->propertyKey(...), array_keys($overrides));
        $parts = [$this->declarationList($this->withoutComments($text) ?? '', $replaced)];
        foreach ($this->declarations($overrides) as $name => $value) {
            $parts[] = $name.':'.$value;
        }
        return implode(';', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function propertyKey(string $property): string
    {
        return str_starts_with($property, '--') ? $property : strtolower($property);
    }

    public function stylesheet(string $text): string
    {
        $this->consume($text);
        $text = $this->withoutComments($text);
        if ($text === null) {
            return '';
        }
        $position = 0;
        return $this->rules($text, $position, 0);
    }

    private function consume(string $text): void
    {
        if (strlen($text) > $this->limits->maximumOutputBytes - $this->bytes) {
            throw new DerivationException('CSS exceeds the cumulative byte budget.');
        }
        $this->bytes += strlen($text);
    }

    private function token(): void
    {
        if (++$this->tokens > $this->limits->maximumNodes * 8) {
            throw new DerivationException('CSS exceeds the cumulative token budget.');
        }
    }

    private function withoutComments(string $text): ?string
    {
        $result = '';
        $quote = null;
        $length = strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $char = $text[$index];
            if ($char === '\\') {
                $this->diagnostics[] = new Diagnostic('blocked-css-escape', 'CSS escapes are outside the accepted stylesheet subset.');
                return null;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '/' && ($text[$index + 1] ?? '') === '*') {
                $end = strpos($text, '*/', $index + 2);
                if ($end === false) {
                    $this->diagnostics[] = new Diagnostic('blocked-stylesheet-comment', 'An unterminated CSS comment was omitted with its stylesheet.');
                    return null;
                }
                // Joining is deliberately conservative: u/**/rl must never evade URL filtering.
                $index = $end + 1;
                continue;
            }
            $result .= $char;
        }
        if ($quote !== null) {
            $this->diagnostics[] = new Diagnostic('blocked-stylesheet-syntax', 'An unterminated CSS string was omitted with its stylesheet.');
            return null;
        }
        return $result;
    }

    private function rules(string $text, int &$position, int $depth): string
    {
        if ($depth > $this->limits->maximumDepth) {
            throw new DerivationException('CSS exceeds the nesting budget.');
        }
        $result = '';
        $length = strlen($text);
        while ($position < $length) {
            $start = $position;
            $delimiter = $this->delimiter($text, $position, '{};');
            $header = trim(substr($text, $start, $position - $start));
            if ($delimiter === null) {
                if ($header !== '' || $depth > 0) {
                    $this->syntax();
                }
                break;
            }
            ++$position;
            if ($delimiter === '}') {
                if ($header !== '' || $depth === 0) {
                    $this->syntax();
                }
                return $result;
            }
            $this->token();
            if ($delimiter === ';') {
                $this->diagnostics[] = new Diagnostic('blocked-stylesheet-rule', 'CSS statements such as imports are not accepted.');
                continue;
            }
            if (preg_match('/\A@(supports|media)\s+(.+)\z/isD', $header, $group) === 1 && $this->conditionAllowed($group[2])) {
                $body = $this->rules($text, $position, $depth + 1);
                if ($body !== '') {
                    $result .= '@'.strtolower($group[1]).' '.trim($group[2]).'{'.$body.'}';
                }
                continue;
            }
            $bodyStart = $position;
            $bodyEnd = $this->blockEnd($text, $position, $depth);
            if ($bodyEnd === null) {
                $this->syntax();
                break;
            }
            if (preg_match('/\A[a-zA-Z0-9_.*#,:>+~\s\[\]="\'|^$()-]+\z/D', $header) !== 1 || !$this->balanced($header)) {
                $this->diagnostics[] = new Diagnostic('blocked-stylesheet-selector', 'An unsupported stylesheet selector or at-rule was omitted.');
                continue;
            }
            $declarations = $this->declarationList(substr($text, $bodyStart, $bodyEnd - $bodyStart));
            if ($declarations !== '') {
                $result .= $header.'{'.$declarations.'}';
            }
        }
        return $result;
    }

    /** Scan a balanced block even when its rule is unsupported; later rules remain usable. */
    private function blockEnd(string $text, int &$position, int $depth): ?int
    {
        $level = 1;
        $length = strlen($text);
        while ($position < $length) {
            $delimiter = $this->delimiter($text, $position, '{}');
            if ($delimiter === null) {
                return null;
            }
            $end = $position++;
            $this->token();
            $level += $delimiter === '{' ? 1 : -1;
            if ($depth + $level > $this->limits->maximumDepth) {
                throw new DerivationException('CSS exceeds the nesting budget.');
            }
            if ($level === 0) {
                return $end;
            }
        }
        return null;
    }

    private function delimiter(string $text, int &$position, string $delimiters): ?string
    {
        $quote = null;
        $length = strlen($text);
        while ($position < $length) {
            $char = $text[$position];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (str_contains($delimiters, $char)) {
                return $char;
            }
            ++$position;
        }
        return null;
    }

    /** @param list<string> $replaced */
    private function declarationList(string $text, array $replaced = []): string
    {
        $result = [];
        foreach ((new CssDeclarations($text))->pairs() as [$name, $value]) {
            $this->token();
            if ($this->declarationAllowed($name, $value) && !in_array($this->propertyKey($name), $replaced, true)) {
                $result[] = $name.':'.$value;
            }
        }
        return implode(';', $result);
    }

    private function declarationAllowed(string $name, string $value): bool
    {
        if (preg_match('/\A(?:--|-)?[a-zA-Z][a-zA-Z0-9-]*\z/D', $name) !== 1
            || in_array(strtolower($name), ['behavior', '-moz-binding'], true)
            || $value === '' || !$this->valueAllowed($value)) {
            $this->diagnostics[] = new Diagnostic('blocked-css', 'A CSS declaration was omitted by the safe output policy.', $name);
            return false;
        }
        return true;
    }

    private function conditionAllowed(string $condition): bool
    {
        return preg_match('/\A[a-zA-Z0-9_.:#,\s()%-]+\z/D', $condition) === 1 && $this->valueAllowed($condition);
    }

    private function valueAllowed(string $value): bool
    {
        return preg_match('/[{}<>\\\\\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|@|(?:url|expression|image|(?:-webkit-)?image-set|-moz-image-rect|src)\s*\(|behavior|binding|\/\*/i', $value) !== 1
            && $this->balanced($value, true);
    }

    private function balanced(string $text, bool $declaration = false): bool
    {
        $quote = null;
        $stack = [];
        for ($index = 0, $length = strlen($text); $index < $length; ++$index) {
            $char = $text[$index];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                if (count($stack) >= $this->limits->maximumDepth) {
                    throw new DerivationException('CSS exceeds the nesting budget.');
                }
                $stack[] = $char === '(' ? ')' : ']';
            } elseif ($char === ')' || $char === ']') {
                if (array_pop($stack) !== $char) {
                    return false;
                }
            } elseif ($declaration && $char === ';') {
                return false;
            }
        }
        return $quote === null && $stack === [];
    }

    private function syntax(): void
    {
        $this->diagnostics[] = new Diagnostic('blocked-stylesheet-syntax', 'Malformed or unsupported stylesheet syntax was omitted.');
    }
}
