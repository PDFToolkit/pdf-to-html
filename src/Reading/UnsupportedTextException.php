<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** A missing explicit font decoder may become a diagnostic; budgets and malformed syntax may not. */
final class UnsupportedTextException extends ReadingException
{
}
