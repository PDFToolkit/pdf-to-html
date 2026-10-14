<?php

declare(strict_types=1);

namespace PDF\Html\Model;

use PDF\Html\Forms\ChoiceOption;
use PDF\Html\Forms\FieldType;

final readonly class FormField
{
    /**
     * @param string|list<string>|null $value
     * @param list<ChoiceOption> $options
     * @param list<int> $selectedIndices
     * @param array<string,string> $actions
     */
    public function __construct(public string $name, public FieldType $type, public int $flags = 0, public string|array|null $value = null, public array $options = [], public array $selectedIndices = [], public ?int $maximumLength = null, public ?string $richText = null, public array $actions = [], public ?string $actionType = null, public ?string $actionTarget = null, public int $submitFlags = 0)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A fully qualified field name must not be empty.');
        }
        if ($maximumLength !== null && $maximumLength < 0) {
            throw new \InvalidArgumentException('A maximum field length cannot be negative.');
        }
    }
}
