<?php

declare(strict_types=1);

namespace PDF\Html\Signatures;

use PDF\Html\DerivationException;

/** A result cannot accidentally be reused for another source or signature field. */
final readonly class SignatureValidationResult
{
    public function __construct(private SignatureValidationRequest $request, public SignatureValidationStatus $status, public string $message)
    {
        if ($message === '' || str_contains($message, "\0") || !mb_check_encoding($message, 'UTF-8')) {
            throw new \InvalidArgumentException('A signature result requires a nonempty UTF-8 explanation.');
        }
    }

    public function assertFor(SignatureValidationRequest $request): void
    {
        if ($this->request !== $request) {
            throw new DerivationException('Signature validation must report on the exact requested source and field.');
        }
    }
}
