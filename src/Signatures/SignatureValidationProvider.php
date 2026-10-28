<?php

declare(strict_types=1);

namespace PDF\Html\Signatures;

/** Application-selected PDF validation, including its explicit trust/revocation policy. */
interface SignatureValidationProvider
{
    public function validate(SignatureValidationRequest $request): SignatureValidationResult;
}
