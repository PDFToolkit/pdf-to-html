<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

final readonly class ChoiceOption
{
    public function __construct(public string $exportValue, public string $displayValue)
    {
    }
}
