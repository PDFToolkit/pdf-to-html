<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

/** PDF /Fields selection: an absent array selects all; parent names include descendants. */
final readonly class FieldSelection
{
    /** @param list<string>|null $fields */
    public function __construct(public ?array $fields = null, public bool $exclude = false)
    {
        if ($fields !== null && !array_is_list($fields)) {
            throw new \InvalidArgumentException('A form action selection must be a list of fully qualified field names.');
        }
        foreach ($fields ?? [] as $field) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException('Selected field names must be nonempty strings.');
            }
        }
    }

    public function contains(string $name): bool
    {
        if ($this->fields === null) {
            return true;
        }
        foreach ($this->fields as $selected) {
            if ($name === $selected || str_starts_with($name, $selected . '.')) {
                return !$this->exclude;
            }
        }
        return $this->exclude;
    }
}
