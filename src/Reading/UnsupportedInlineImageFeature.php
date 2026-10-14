<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** A codec extension needs an explicitly supplied endpoint decoder, not PDF token scanning. */
final class UnsupportedInlineImageFeature extends ReadingException
{
}
