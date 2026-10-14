<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** Whether an inconsistent inverse structure association is reported or rejected. */
enum StructureValidationMode
{
    case Permissive;
    case Strict;
}
