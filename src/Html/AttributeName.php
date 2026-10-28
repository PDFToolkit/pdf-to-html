<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;

/** HTML attribute syntax, shared by UserProperties, filtering and serialization. */
final class AttributeName
{
    public function userProperty(string $name): string
    {
        $normalized = $this->replaceForbidden($name);
        if ($normalized === null) {
            throw new DerivationException('A user property name must contain valid UTF-8.');
        }

        // Only ASCII case folds in HTML attribute names; Unicode names remain distinct.
        return strtolower($normalized);
    }

    public function valid(string $name): bool
    {
        return $name !== '' && $this->replaceForbidden($name) === $name;
    }

    private function replaceForbidden(string $name): ?string
    {
        return preg_replace_callback('/./us', function (array $match): string {
            $point = mb_ord($match[0], 'UTF-8');
            $forbidden = $point === false || $point <= 0x20 || ($point >= 0x7f && $point <= 0x9f)
                || in_array($match[0], ['"', "'", '/', '=', '>'], true)
                || ($point >= 0xfdd0 && $point <= 0xfdef) || ($point & 0xffff) >= 0xfffe;

            return $forbidden ? '_' : $match[0];
        }, $name);
    }
}
