<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ByteCursor;
use PDF\Syntax\Object\DictionaryType;

/** An application codec positions the cursor immediately after its exact encoded image bytes. */
interface InlineImageBoundaryProvider
{
    public function locate(ByteCursor $cursor, DictionaryType $dictionary, ReadingBudget $budget): void;
}
