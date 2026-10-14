<?php

declare(strict_types=1);

namespace PDF\Html\Model;

use PDF\Html\Forms\ChoiceOption;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\ResetFormAction;
use PDF\Html\Forms\SubmitFormAction;

final readonly class FormField
{
    /**
     * @param string|list<string>|null $value
     * @param list<ChoiceOption> $options
     * @param list<int> $selectedIndices
     * @param array<string,string> $actions
     * @param string|list<string>|null $defaultValue PDF /DV; null means no default value, not the initial /V.
     */
    public function __construct(public string $name, public FieldType $type, public int $flags = 0, public string|array|null $value = null, public array $options = [], public array $selectedIndices = [], public ?int $maximumLength = null, public ?string $richText = null, public array $actions = [], public ?string $actionType = null, public ?string $actionTarget = null, public int $submitFlags = 0, public string|array|null $defaultValue = null, public ?ResetFormAction $resetAction = null, public ?SubmitFormAction $submitAction = null, public ?string $mappingName = null)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A fully qualified field name must not be empty.');
        }
        if ($maximumLength !== null && $maximumLength < 0) {
            throw new \InvalidArgumentException('A maximum field length cannot be negative.');
        }
        if ($resetAction !== null && $submitAction !== null) {
            throw new \InvalidArgumentException('A push button cannot have both ResetForm and SubmitForm actions.');
        }
    }

    public function resetFormAction(): ?ResetFormAction
    {
        return $this->resetAction ?? ($this->actionType === 'ResetForm' ? new ResetFormAction() : null);
    }

    public function submitFormAction(): ?SubmitFormAction
    {
        return $this->submitAction ?? ($this->actionType === 'SubmitForm' && $this->actionTarget !== null && $this->actionTarget !== '' ? new SubmitFormAction($this->actionTarget, $this->submitFlags) : null);
    }
}
