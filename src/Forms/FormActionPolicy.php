<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

/** Enables library-owned form actions without enabling embedded PDF ECMAScript. */
final readonly class FormActionPolicy
{
    public function __construct(public bool $allowReset = false, public bool $allowSubmit = false)
    {
    }

    public function enabled(): bool
    {
        return $this->allowReset || $this->allowSubmit;
    }
}
