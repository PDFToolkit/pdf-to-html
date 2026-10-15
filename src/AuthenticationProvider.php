<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Reading\FileBytes;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\SourceIntegrityPolicy;

/** Annex C: authenticate the same source before any tagged interpretation. */
interface AuthenticationProvider
{
    public function authenticate(FileBytes $encryptedFile, string $filename, SourceIntegrityPolicy $sourceIntegrity): ReadDocument;
}
