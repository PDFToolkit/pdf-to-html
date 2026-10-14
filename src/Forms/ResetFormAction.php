<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

/** PDF ResetForm /Fields and Include/Exclude, independent of PDF JavaScript. */
final readonly class ResetFormAction
{
    public FieldSelection $selection;

    /** @param list<string>|null $fields */
    public function __construct(?array $fields = null, bool $exclude = false)
    {
        $this->selection = new FieldSelection($fields, $exclude);
    }
}
