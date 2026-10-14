<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Model\TaggedDocument;
use PDF\Reading\FileBytes;

/** Annex C: authenticate/decrypt explicitly before exposing a tagged read model. */
interface AuthenticationProvider
{
    public function authenticate(FileBytes $encryptedFile, string $filename): TaggedDocument;
}
