<?php

declare(strict_types=1);

namespace PDF\Html\Signatures;

enum SignatureValidationStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Indeterminate = 'indeterminate';
    case IntegrityVerified = 'integrity-verified';
    case NotValidated = 'not-validated';
    case Unsigned = 'unsigned';
}
