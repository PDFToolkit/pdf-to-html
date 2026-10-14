<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class Widget implements SourceNode
{
    /**
     * @param array{0:int|float,1:int|float,2:int|float,3:int|float} $rectangle
     * @param null|array{0:int|float,1:int|float,2:int|float,3:int|float} $pageBox
     * @param list<string> $appearanceStates
     * @param list<SourceNode> $appearance
     * @param null|list<int|float> $borderColor
     * @param null|list<int|float> $backgroundColor
     */
    public function __construct(public FormField $field, public ?int $page = null, public int $annotationFlags = 0, public array $rectangle = [0,0,0,0], public ?array $pageBox = null, public string $appearanceState = 'Off', public array $appearanceStates = [], public ?string $caption = null, public array $appearance = [], public ?string $highlighting = null, public ?string $borderStyle = null, public ?float $borderWidth = null, public ?array $borderColor = null, public ?array $backgroundColor = null, public ?int $alignment = null, public ?int $optionIndex = null, public ?string $signatureStatus = null)
    {
    }
}
