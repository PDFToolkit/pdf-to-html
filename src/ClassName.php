<?php

declare(strict_types=1);

namespace PDF\Html;

final class ClassName
{
    public function encode(string $name): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $name) === 1 && !str_starts_with($name, 'pdf-class-')) {
            return $name;
        }
        return 'pdf-class-' . bin2hex($name);
    }
}
