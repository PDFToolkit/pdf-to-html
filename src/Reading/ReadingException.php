<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** Malformed, ambiguous and unsupported input must never be silently derived. */
class ReadingException extends \RuntimeException
{
}
