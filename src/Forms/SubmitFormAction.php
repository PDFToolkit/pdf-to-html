<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

/** PDF SubmitForm metadata; only HTML Form export maps to the reference's Table 5. */
final readonly class SubmitFormAction
{
    public FieldSelection $selection;

    /** @param list<string>|null $fields */
    public function __construct(public string $target, public int $flags = 4, ?array $fields = null)
    {
        if ($target === '' || $flags < 0 || $flags > 0xffffffff) {
            throw new \InvalidArgumentException('SubmitForm requires a destination and unsigned 32-bit flags.');
        }
        $this->selection = new FieldSelection($fields, ($flags & 1) !== 0);
    }

    public function usesHtmlFormExport(): bool
    {
        return ($this->flags & 4) !== 0 && ($this->flags & (32 | 256)) === 0;
    }

    public function supportsHtmlSubmission(): bool
    {
        return $this->usesHtmlFormExport() && ($this->flags & ~31) === 0;
    }
}
